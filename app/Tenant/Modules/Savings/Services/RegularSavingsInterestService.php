<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsInterestPosting;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegularSavingsInterestService
{
    public function __construct(
        protected FixedDepositCalculator $calculator,
        protected SavingsJournalService $journal,
    ) {}

    public function processAccount(SavingsAccount $account, int $actorId): bool
    {
        if (! in_array($account->account_type, ['mandatory', 'voluntary'], true)) {
            return false;
        }
        if ($account->status !== 'active') {
            return false;
        }

        $account->loadMissing('savingsProduct');
        $product = $account->savingsProduct;

        if (! $product?->interest_enabled) {
            return false;
        }
        if (! ((float) ($product->interest_rate ?? 0) > 0)) {
            return false;
        }
        if (! $product->interest_expense_account_id || ! $product->interest_payable_account_id) {
            return false;
        }

        return (bool) DB::connection('tenant')->transaction(function () use ($account, $actorId): bool {
            $account = SavingsAccount::on('tenant')->lockForUpdate()->findOrFail($account->id);
            $product = $account->savingsProduct()->first();

            $periodStart = Carbon::parse($account->last_interest_posted_at ?? $account->created_at);
            $periodEnd = Carbon::today();
            $interest = $this->calculator->calculateInterest(
                (float) $account->balance,
                (float) $product->interest_rate,
                $periodStart,
                $periodEnd,
            );

            if ($interest <= 0) {
                return false;
            }

            $expenseAccount = ChartOfAccount::on('tenant')->findOrFail($product->interest_expense_account_id);
            $payableAccount = ChartOfAccount::on('tenant')->findOrFail($product->interest_payable_account_id);

            $je = $this->journal->postInterest(
                $account,
                $expenseAccount,
                $payableAccount,
                $interest,
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
                $actorId,
                'SAVINGS_INTEREST',
            );

            SavingsInterestPosting::on('tenant')->create([
                'savings_account_id' => $account->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'principal' => $account->balance,
                'rate' => $product->interest_rate,
                'interest_amount' => $interest,
                'payout_type' => 'compound',
                'journal_entry_id' => $je?->id,
                'posted_by' => $actorId,
                'created_at' => now(),
            ]);

            $account->increment('balance', $interest);
            $account->update(['last_interest_posted_at' => now()]);

            return true;
        });
    }

    public function runBatchPosting(int $actorId): array
    {
        $summary = ['posted' => 0, 'skipped' => 0, 'errors' => []];

        if (! OnboardingSettings::current()->regular_savings_interest_enabled) {
            return $summary;
        }

        SavingsAccount::on('tenant')
            ->whereIn('account_type', ['mandatory', 'voluntary'])
            ->where('status', 'active')
            ->whereHas('savingsProduct', fn ($q) => $q->where('interest_enabled', true))
            ->with('savingsProduct')
            ->each(function (SavingsAccount $account) use ($actorId, &$summary) {
                try {
                    if ($this->processAccount($account, $actorId)) {
                        $summary['posted']++;
                    } else {
                        $summary['skipped']++;
                    }
                } catch (UniqueConstraintViolationException) {
                    // Only reachable under true concurrency — two processes racing to post the
                    // same account before either commits. Sequential re-runs return early at the
                    // $interest <= 0 guard (period_start == period_end after first posting).
                    $summary['skipped']++;
                } catch (\Throwable $e) {
                    $summary['errors'][] = [
                        'account_no' => $account->account_no,
                        'reason' => $e->getMessage(),
                    ];
                    Log::error("Regular savings interest error on {$account->account_no}: ".$e->getMessage());
                }
            });

        return $summary;
    }
}
