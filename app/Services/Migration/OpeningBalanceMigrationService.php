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

class OpeningBalanceMigrationService implements MigrationImportInterface
{
    public function __construct(
        protected SavingsJournalService $journalService,
    ) {}

    public function process(Collection $rows): array
    {
        $imported = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNum = $index + 1;
            $memberNumber = trim((string) ($row[0] ?? ''));
            $accountNo = trim((string) ($row[1] ?? ''));
            $balance = $row[2] ?? null;
            $asOfDate = trim((string) ($row[3] ?? ''));
            $notes = trim((string) ($row[4] ?? ''));

            if (! $memberNumber && ! $accountNo) {
                continue;
            }

            $missing = $this->validateRequired(compact('memberNumber', 'accountNo', 'balance', 'asOfDate'));
            if ($missing) {
                $errors[] = "Row {$rowNum}: Missing — {$missing}.";

                continue;
            }

            if (! is_numeric($balance) || (float) $balance < 0) {
                $errors[] = "Row {$rowNum}: Opening balance must be a positive number.";

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

            $date = $this->parseDate($asOfDate);
            if (! $date) {
                $errors[] = "Row {$rowNum}: Invalid date '{$asOfDate}'. Use YYYY-MM-DD.";

                continue;
            }

            try {
                DB::connection('tenant')->transaction(function () use ($account, $balance, $date, $notes) {
                    $account->update(['balance' => (float) $balance]);

                    $txn = Transaction::create([
                        'reference' => 'MIG-OB-'.date('Ymd').'-'.mt_rand(10000, 99999),
                        'member_id' => $account->member_id,
                        'type' => 'deposit',
                        'amount' => (float) $balance,
                        'payment_mode' => 'migration',
                        'deposited_by' => 'System (Migration)',
                        'transaction_date' => $date,
                        'account_id' => $account->id,
                        'account_type' => SavingsAccount::class,
                        'narration' => $notes ?: 'Migration Opening Balance',
                        'is_migrated' => true,
                        'created_by' => Auth::id(),
                    ]);

                    $this->journalService->postOpeningBalance($txn, $account);
                });
                $imported++;
            } catch (\Exception $e) {
                $errors[] = "Row {$rowNum}: Failed — {$e->getMessage()}";
            }
        }

        return compact('imported', 'errors');
    }

    private function validateRequired(array $fields): ?string
    {
        $missing = [];
        if (! $fields['memberNumber']) {
            $missing[] = 'Member Number';
        }
        if (! $fields['accountNo']) {
            $missing[] = 'Account Number';
        }
        if (is_null($fields['balance']) || $fields['balance'] === '') {
            $missing[] = 'Opening Balance';
        }
        if (! $fields['asOfDate']) {
            $missing[] = 'As Of Date';
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
