<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsInterestPosting;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixedDepositInterestService
{
    public function __construct(
        protected FixedDepositCalculator $calculator,
        protected FixedDepositMaturityService $maturity,
        protected SavingsJournalService $journal,
    ) {}

    /**
     * Process one FD account: post due interest and execute maturity action.
     * Safe to call multiple times — idempotent via DB unique key.
     */
    public function processAccount(SavingsAccount $account, int $actorId): void
    {
        if (! $account->isFixed() || $account->status !== 'active') {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($account, $actorId) {
            // Re-fetch with lock to prevent concurrent double-posting
            $account = SavingsAccount::on('tenant')
                ->lockForUpdate()
                ->findOrFail($account->id);

            $today = Carbon::today();

            if ($account->maturity_date && Carbon::parse($account->maturity_date)->lte($today)) {
                $this->postMaturityInterest($account, $actorId);

                return;
            }

            if ($account->next_interest_date
                && Carbon::parse($account->next_interest_date)->lte($today)
                && in_array($account->savingsProduct?->interest_payout_type, ['periodic_payout', 'compound'], true)
            ) {
                $this->postPeriodicInterest($account, $actorId);
            }
        });
    }

    /**
     * Sweep all active FDs with overdue interest or maturity dates.
     * Returns a summary array.
     */
    public function runMonthEndSweep(int $actorId): array
    {
        $summary = ['posted' => 0, 'skipped' => 0, 'matured' => 0, 'errors' => []];

        SavingsAccount::on('tenant')
            ->where('account_type', 'fixed')
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereDate('next_interest_date', '<=', today())
                ->orWhereDate('maturity_date', '<=', today())
            )
            ->each(function (SavingsAccount $account) use ($actorId, &$summary) {
                try {
                    $this->processAccount($account, $actorId);
                    $account->refresh();
                    if (in_array($account->status, ['matured', 'closed'], true)) {
                        $summary['matured']++;
                    } else {
                        $summary['posted']++;
                    }
                } catch (UniqueConstraintViolationException) {
                    $summary['skipped']++;
                } catch (\Throwable $e) {
                    $summary['errors'][] = [
                        'account_no' => $account->account_no,
                        'reason' => $e->getMessage(),
                    ];
                    Log::error("FD sweep error on {$account->account_no}: ".$e->getMessage());
                }
            });

        return $summary;
    }

    private function postMaturityInterest(SavingsAccount $account, int $actorId): void
    {
        $product = $account->savingsProduct;
        $from = Carbon::parse($account->last_interest_posted_at ?? $account->created_at);
        $to = Carbon::parse($account->maturity_date);
        $principal = (float) $account->balance;
        $rate = (float) ($product?->interest_rate ?? $account->interest_rate ?? 0);
        $interest = $this->calculator->calculateInterest($principal, $rate, $from, $to);

        if ($interest > 0) {
            $this->postAndRecord($account, $product, $interest, $from->toDateString(), $to->toDateString(), $actorId);
            $account->increment('balance', $interest);
        }

        $account->update(['last_interest_posted_at' => now()]);
        $this->maturity->execute($account, $actorId);
    }

    private function postPeriodicInterest(SavingsAccount $account, int $actorId): void
    {
        $product = $account->savingsProduct;
        $from = Carbon::parse($account->last_interest_posted_at ?? $account->created_at);
        $to = Carbon::parse($account->next_interest_date);
        $principal = (float) $account->balance;
        $rate = (float) ($product?->interest_rate ?? $account->interest_rate ?? 0);
        $interest = $this->calculator->calculateInterest($principal, $rate, $from, $to);

        if ($interest <= 0) {
            return;
        }

        $payoutType = $product?->interest_payout_type ?? 'periodic_payout';
        $this->postAndRecord($account, $product, $interest, $from->toDateString(), $to->toDateString(), $actorId);

        if ($payoutType === 'compound') {
            $account->increment('balance', $interest);
        } else {
            // periodic_payout — credit member's payout savings account balance
            $payoutAccount = $account->payoutSavingsAccount;
            if ($payoutAccount) {
                $payoutAccount->increment('balance', $interest);
            }
        }

        $frequency = $product?->interest_posting_frequency ?? 'monthly';
        $nextDate = $this->calculator->nextInterestDate($to, $frequency);
        $account->update([
            'last_interest_posted_at' => now(),
            'next_interest_date' => $nextDate,
        ]);
    }

    private function postAndRecord(
        SavingsAccount $account,
        mixed $product,
        float $interest,
        string $periodStart,
        string $periodEnd,
        int $actorId,
    ): void {
        $expenseAccountId = $product?->interest_expense_account_id;
        $payableAccountId = $product?->interest_payable_account_id;

        $expenseAccount = $expenseAccountId
            ? ChartOfAccount::on('tenant')->findOrFail($expenseAccountId)
            : null;
        $creditAccount = $payableAccountId
            ? ChartOfAccount::on('tenant')->findOrFail($payableAccountId)
            : null;

        $je = null;
        if ($expenseAccount && $creditAccount) {
            $je = $this->journal->postInterest(
                $account, $expenseAccount, $creditAccount,
                $interest, $periodStart, $periodEnd, $actorId,
            );
        }

        SavingsInterestPosting::on('tenant')->create([
            'savings_account_id' => $account->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'principal' => $account->balance,
            'rate' => $product?->interest_rate ?? $account->interest_rate ?? 0,
            'interest_amount' => $interest,
            'payout_type' => $product?->interest_payout_type ?? 'at_maturity',
            'journal_entry_id' => $je?->id,
            'posted_by' => $actorId,
            'created_at' => now(),
        ]);
    }
}
