<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Savings\Contracts\FdMaturityAccountingServiceInterface;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixedDepositMaturityService
{
    public function __construct(
        protected FixedDepositCalculator $calculator,
        protected FdMaturityAccountingServiceInterface $accounting,
    ) {}

    /**
     * Execute the maturity action for a fixed deposit account.
     * Called inside processAccount() after maturity interest has been posted.
     */
    public function execute(SavingsAccount $account, int $actorId): void
    {
        $effectiveAction = $account->maturity_action
            ?? $account->savingsProduct?->maturity_action
            ?? 'manual';

        match ($effectiveAction) {
            'auto_rollover' => $this->rollover($account, $actorId),
            'convert_to_savings' => $this->convertToSavings($account, $actorId),
            default => $this->markMatured($account),
        };
    }

    /**
     * Process a manually chosen maturity action (from API).
     * Handles rollover, convert, and close actions explicitly.
     */
    public function processManualAction(SavingsAccount $account, string $action, int $actorId): void
    {
        DB::connection('tenant')->transaction(function () use ($account, $action, $actorId) {
            match ($action) {
                'rollover' => $this->rollover($account, $actorId),
                'convert'  => $this->convertToSavings($account, $actorId),
                'close'    => $this->close($account, $actorId),
                default    => $account->update(['status' => 'closed']),
            };
        });
    }

    private function rollover(SavingsAccount $account, int $actorId): void
    {
        $product = $account->savingsProduct;
        $tenor = $account->tenor_months ?? $product?->default_tenor_months ?? 6;
        $newRate = (float) ($product?->interest_rate ?? $account->interest_rate ?? 0);
        $now = Carbon::now();

        // Mark old account as matured
        $account->update(['status' => 'matured']);

        $payoutType = $product?->interest_payout_type ?? 'at_maturity';
        $nextInterestDate = $payoutType !== 'at_maturity'
            ? $this->calculator->nextInterestDate($now, $product?->interest_posting_frequency ?? 'monthly')
            : null;

        $newAccount = SavingsAccount::create([
            'member_id' => $account->member_id,
            'savings_product_id' => $account->savings_product_id,
            'account_no' => $this->newAccountNo($account),
            'account_type' => 'fixed',
            'balance' => $account->balance,
            'interest_rate' => $newRate,
            'status' => 'active',
            'branch_id' => $account->branch_id,
            'tenor_months' => $tenor,
            'maturity_date' => $now->copy()->addMonths($tenor),
            'next_interest_date' => $nextInterestDate,
            'maturity_action' => $account->maturity_action ?? $product?->maturity_action,
            'payout_savings_account_id' => $account->payout_savings_account_id,
            'consider_min_balance' => $account->consider_min_balance,
        ]);

        $this->accounting->postRollover($account, $newAccount, $actorId);

        Log::info("FD auto-rolled over: {$account->account_no} closed, new account opened.");
    }

    private function convertToSavings(SavingsAccount $account, int $actorId): void
    {
        $product = $account->savingsProduct;
        $targetProductId = $product?->convert_to_product_id;

        if (! $targetProductId) {
            Log::warning("FD convert_to_savings: no convert_to_product_id on product {$product?->name}. Falling back to manual.");
            $this->markMatured($account);

            return;
        }

        // Must call accounting BEFORE updating account_type so the resolver still sees 'fixed'
        $this->accounting->postConversion($account, $actorId);

        $account->update([
            'account_type' => 'voluntary',
            'savings_product_id' => $targetProductId,
            'status' => 'active',
            'maturity_date' => null,
            'next_interest_date' => null,
            'tenor_months' => null,
        ]);
    }

    private function close(SavingsAccount $account, int $actorId): void
    {
        $targetId = $account->payout_savings_account_id;

        if (! $targetId) {
            throw new \RuntimeException("FD close: no payout_savings_account_id set on account {$account->account_no}. Cannot close without a target savings account.");
        }

        $target = SavingsAccount::on('tenant')->find($targetId);

        if (! $target) {
            throw new \RuntimeException("FD close: payout target account ID {$targetId} not found for {$account->account_no}.");
        }

        $this->accounting->postPayout($account, $target, $actorId);
        $target->increment('balance', $account->balance);

        $account->update([
            'status'  => 'closed',
            'balance' => 0,
        ]);
    }

    private function markMatured(SavingsAccount $account): void
    {
        $account->update(['status' => 'matured']);
    }

    private function newAccountNo(SavingsAccount $account): string
    {
        $prefix = strtoupper(substr($account->account_no, 0, 3));
        $seq = SavingsAccount::withTrashed()
            ->where('savings_product_id', $account->savings_product_id)
            ->count() + 1;

        return $prefix.'-'.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
