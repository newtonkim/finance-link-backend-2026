<?php

namespace App\Services\Migration;

use App\Contracts\MigrationImportInterface;
use App\Models\Member;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransactionHistoryMigrationService implements MigrationImportInterface
{
    public function __construct(
        protected SavingsJournalService $journalService,
    ) {}

    public function process(Collection $rows): array
    {
        $imported = 0;
        $errors = [];
        $seenRefs = [];

        // Sort by date ASC so running balance is computed correctly
        $sorted = $rows->sortBy(fn ($r) => $r[4] ?? '');

        foreach ($sorted as $index => $row) {
            $rowNum = $index + 1;
            $memberNumber = trim((string) ($row[0] ?? ''));
            $accountNo = trim((string) ($row[1] ?? ''));
            $type = strtolower(trim((string) ($row[2] ?? '')));
            $amount = $row[3] ?? null;
            $dateRaw = trim((string) ($row[4] ?? ''));
            $narration = trim((string) ($row[5] ?? ''));
            $paymentMode = strtolower(trim((string) ($row[6] ?? ''))) ?: 'cash';
            $reference = trim((string) ($row[7] ?? ''));

            if (! $memberNumber && ! $accountNo) {
                continue;
            }

            $missing = $this->validateRequired(compact('memberNumber', 'accountNo', 'type', 'amount', 'dateRaw'));
            if ($missing) {
                $errors[] = "Row {$rowNum}: Missing — {$missing}.";

                continue;
            }

            if (! in_array($type, ['deposit', 'withdrawal'])) {
                $errors[] = "Row {$rowNum}: Type must be 'deposit' or 'withdrawal'.";

                continue;
            }

            if (! is_numeric($amount) || (float) $amount <= 0) {
                $errors[] = "Row {$rowNum}: Amount must be a positive number.";

                continue;
            }

            $date = $this->parseDate($dateRaw);
            if (! $date) {
                $errors[] = "Row {$rowNum}: Invalid date '{$dateRaw}'. Use YYYY-MM-DD.";

                continue;
            }

            if ($reference && in_array($reference, $seenRefs)) {
                $errors[] = "Row {$rowNum}: Duplicate reference '{$reference}' — skipped.";

                continue;
            }

            $member = Member::where('member_number', $memberNumber)->first();
            if (! $member) {
                $errors[] = "Row {$rowNum}: Member '{$memberNumber}' not found.";

                continue;
            }

            $account = SavingsAccount::where('account_no', $accountNo)
                ->where('member_id', $member->id)
                ->first();

            if (! $account) {
                $errors[] = "Row {$rowNum}: Account '{$accountNo}' not found for member '{$memberNumber}'.";

                continue;
            }

            $amount = (float) $amount;

            if ($type === 'withdrawal' && $account->balance < $amount) {
                $errors[] = "Row {$rowNum}: Withdrawal of {$amount} exceeds balance {$account->balance} for account '{$accountNo}'.";

                continue;
            }

            try {
                DB::connection('tenant')->transaction(function () use (
                    $account, $type, $amount, $date, $narration, $paymentMode, $reference
                ) {
                    $ref = $reference ?: 'MIG-TXN-'.date('Ymd').'-'.mt_rand(10000, 99999);

                    $txn = Transaction::create([
                        'reference' => $ref,
                        'member_id' => $account->member_id,
                        'type' => $type,
                        'amount' => $amount,
                        'payment_mode' => $paymentMode,
                        'deposited_by' => 'System (Migration)',
                        'transaction_date' => $date,
                        'account_id' => $account->id,
                        'account_type' => SavingsAccount::class,
                        'narration' => $narration ?: ucfirst($type).' (Migrated)',
                        'is_migrated' => true,
                        'created_by' => Auth::id(),
                    ]);

                    $newBalance = $type === 'deposit'
                        ? $account->balance + $amount
                        : $account->balance - $amount;

                    $account->update(['balance' => $newBalance]);

                    if ($type === 'deposit') {
                        $this->journalService->postDeposit($txn, $account);
                    } else {
                        $this->journalService->postWithdrawal($txn, $account);
                    }
                });

                if ($reference) {
                    $seenRefs[] = $reference;
                }
                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Row {$rowNum}: Failed — {$e->getMessage()}";
            }
        }

        return compact('imported', 'errors');
    }

    private function validateRequired(array $f): ?string
    {
        $missing = [];
        if (! $f['memberNumber']) {
            $missing[] = 'Member Number';
        }
        if (! $f['accountNo']) {
            $missing[] = 'Account Number';
        }
        if (! $f['type']) {
            $missing[] = 'Transaction Type';
        }
        if (is_null($f['amount']) || $f['amount'] === '') {
            $missing[] = 'Amount';
        }
        if (! $f['dateRaw']) {
            $missing[] = 'Transaction Date';
        }

        return $missing ? implode(', ', $missing) : null;
    }

    private function parseDate(string $raw): ?string
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->toDateString();
        } catch (\Exception) {
            return null;
        }
    }
}
