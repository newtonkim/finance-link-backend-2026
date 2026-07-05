<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SavingsAccountStatementService implements SavingsAccountStatementServiceInterface
{
    public function buildStatement(int $savingsAccountId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $account = SavingsAccount::with(['member', 'savingsProduct'])
            ->whereNull('deleted_at')
            ->find($savingsAccountId);

        if (! $account) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException("Savings account {$savingsAccountId} not found.");
        }

        $accountMeta = [
            'id'           => $account->id,
            'account_no'   => $account->account_no,
            'account_type' => ucwords((string) $account->account_type),
            'product_name' => $account->savingsProduct?->name,
        ];
        $memberMeta = [
            'id'            => $account->member?->id,
            'name'          => $account->member?->name,
            'member_number' => $account->member?->member_number,
            'address'       => $account->member?->address,
            'address_city'  => null,
        ];

        $branchId   = $account->member?->branch_id ?? $account->branch_id;
        $branchRow  = $branchId
            ? \Illuminate\Support\Facades\DB::connection('tenant')
                ->table('branches')
                ->where('id', $branchId)
                ->whereNull('deleted_at')
                ->first(['name'])
            : null;
        $branchMeta = ['name' => $branchRow?->name ?? null];

        $dateTo   = $dateTo   ?? now()->toDateString();
        $dateFrom = $dateFrom ?? now()->subDays(90)->toDateString();

        $opening = $this->sumCreditsMinusDebits(
            $this->baseQuery($savingsAccountId)->whereDate('transaction_date', '<', $dateFrom)->get()
        );

        $periodRows = $this->baseQuery($savingsAccountId)
            ->whereBetween('transaction_date', [$dateFrom, $dateTo])
            ->orderBy('transaction_date')->orderBy('id')
            ->get();

        $running = $opening;
        $totalCredit = 0.0;
        $totalDebit  = 0.0;
        $warnings    = [];
        $rows        = [];

        $knownTypes = ['deposit', 'transfer_in', 'interest', 'withdrawal', 'withdraw', 'transfer_out', 'charge', 'general-charge', 'deposit-charge', 'withdraw-charge'];

        foreach ($periodRows as $r) {
            [$credit, $debit] = $this->classify($r);
            if ($credit === 0.0 && $debit === 0.0 && ! in_array($r->type, $knownTypes, true)) {
                $warnings[] = "Unknown transaction type '{$r->type}' on row {$r->id} — excluded.";
                continue;
            }
            $running += $credit - $debit;
            $totalCredit += $credit;
            $totalDebit  += $debit;
            $rows[] = [
                'id'              => $r->id,
                // Always YYYY-MM-DD regardless of whether the underlying column is DATE or DATETIME
                'date'            => substr((string) $r->transaction_date, 0, 10),
                'description'     => $this->describe($r),
                'credit'          => round($credit, 2),
                'debit'           => round($debit, 2),
                'running_balance' => round($running, 2),
                'is_reversal'     => ! empty($r->reversal_of),
            ];
        }

        $closing = $opening + $totalCredit - $totalDebit;
        $this->assertReconciles($closing, $rows);

        return [
            'account' => $accountMeta, 'member' => $memberMeta, 'branch' => $branchMeta,
            'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'statement_date' => now()->toDateString()],
            'balances' => [
                'opening'      => round($opening, 2),
                'total_credit' => round($totalCredit, 2),
                'total_debit'  => round($totalDebit, 2),
                'closing'      => round($closing, 2),
                'count'        => count($rows),
            ],
            'transactions' => $rows,
            'warnings'     => $warnings,
        ];
    }

    private function describe(\App\Tenant\Modules\Transactions\Models\Transaction $r): string
    {
        static $labels = [
            'deposit' => 'Savings Deposit',
            'withdrawal' => 'Savings Withdrawal',
            'withdraw' => 'Savings Withdrawal',
            'transfer_in' => 'Account Transfer In',
            'transfer_out' => 'Account Transfer Out',
            'charge' => 'Charge',
            'general-charge' => 'General Charge',
            'deposit-charge' => 'Deposit Charge',
            'withdraw-charge' => 'Withdrawal Charge',
            'interest' => 'Interest',
        ];
        $label = $labels[$r->type] ?? ucwords(str_replace(['_', '-'], ' ', (string) $r->type));
        $narration = trim((string) ($r->narration ?? ''));
        return $narration === '' ? $label : "{$label} — {$narration}";
    }

    private function assertReconciles(float $closing, array $rows): void
    {
        if (count($rows) === 0) {
            return;
        }
        $last = end($rows)['running_balance'];
        if (abs($closing - $last) > 0.01) {
            throw new \RuntimeException("Statement does not reconcile: closing={$closing}, running_balance(last)={$last}");
        }
    }

    /**
     * Real data uses inconsistent account_type values:
     *   - NULL (legacy MemberHelpers registration path)
     *   - Morph aliases like 'deposit'/'withdrawal'/etc. (modern SavingsAccountController path)
     *   - Occasionally the FQCN itself (factory-created test rows)
     * Loans store 'loan' / 'loan_transaction' — those are the only types we must exclude.
     */
    private function baseQuery(int $savingsAccountId): Builder
    {
        return Transaction::query()
            ->where('account_id', $savingsAccountId)
            ->where(function ($q) {
                $q->whereNull('account_type')
                  ->orWhereNotIn('account_type', ['loan', 'loan_transaction']);
            })
            ->whereNull('group_savings_account_id')
            ->where('is_reversed', 0)
            ->whereNull('deleted_at');
    }

    private function sumCreditsMinusDebits(Collection $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $r) {
            [$c, $d] = $this->classify($r);
            $sum += $c - $d;
        }

        return $sum;
    }

    /** Returns [credit, debit] from a Transaction row. */
    private function classify(Transaction $r): array
    {
        $type        = (string) $r->type;
        $amount      = (float) $r->amount;
        $chargeAmount = (float) ($r->charge_amount ?? 0);

        if (in_array($type, ['deposit', 'transfer_in', 'interest'], true)) {
            return [$amount, 0.0];
        }
        if (in_array($type, ['withdrawal', 'withdraw', 'transfer_out'], true)) {
            return [0.0, $amount];
        }

        if ($this->isWithdrawalChargeMarker($r)) {
            return [0.0, 0.0];
        }

        // All charge types: use `amount` when present (modern path),
        // otherwise fall back to `charge_amount` (legacy MemberHelpers stores
        // the fee in `charge_amount` and leaves `amount = 0`).
        if (in_array($type, ['charge', 'general-charge', 'deposit-charge', 'withdraw-charge', 'withdrawal-charge'], true)) {
            return [0.0, $amount > 0 ? $amount : $chargeAmount];
        }

        return [0.0, 0.0]; // unknown type — caller emits warning
    }

    private function isWithdrawalChargeMarker(Transaction $transaction): bool
    {
        $type = strtolower((string) $transaction->type);

        if (! str_contains($type, 'charge')) {
            return false;
        }

        if ((float) $transaction->amount > 0) {
            return false;
        }

        if (str_contains($type, 'withdraw')) {
            return true;
        }

        $text = strtolower(trim(implode(' ', array_filter([
            $transaction->narration,
            $transaction->charge_name,
        ]))));

        if (str_contains($text, 'withdraw')) {
            return true;
        }

        if (! $transaction->receipt_number) {
            return false;
        }

        return Transaction::query()
            ->where('receipt_number', $transaction->receipt_number)
            ->where('account_id', $transaction->account_id)
            ->whereIn('type', ['withdrawal', 'withdraw'])
            ->exists();
    }
}
