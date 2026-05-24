<?php

namespace App\Tenant\Modules\Members\Services;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Members\Models\MemberCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MemberChargeService
{
    /**
     * Attempt to collect all pending charges for a member from the given savings account.
     * Charges are collected FIFO (oldest applied_at first).
     * Must be called inside the caller's DB transaction.
     */
    public function collectPendingCharges(
        Member $member,
        SavingsAccount $account,
        SavingsJournalService $savingsJournal
    ): void {
        $pending = MemberCharge::where('member_id', $member->id)
            ->where('status', 'pending')
            ->orderBy('applied_at', 'asc')
            ->get();

        foreach ($pending as $memberCharge) {
            // Continue trying subsequent charges even if one cannot be collected
            $this->collectCharge($memberCharge, $account, $savingsJournal);
        }
    }

    /**
     * Collect a single pending MemberCharge from the given savings account.
     * Deducts balance, creates a Transaction, posts GL, and marks the charge as paid.
     * Returns true if collected, false if skipped (insufficient balance or not pending).
     * Must be called inside the caller's DB transaction.
     */
    public function collectCharge(
        MemberCharge $memberCharge,
        SavingsAccount $account,
        SavingsJournalService $savingsJournal
    ): bool {
        if ($memberCharge->status !== 'pending') {
            return false;
        }

        $chargeAmount = (float) $memberCharge->amount;

        if ($chargeAmount <= 0 || (float) $account->balance < $chargeAmount) {
            return false;
        }

        // Deduct from in-memory balance (caller saves the account)
        $account->balance -= $chargeAmount;
        $account->save();

        $ref = 'REG-CHG-'.date('Ymd').'-'.mt_rand(10000, 99999);
        $narration = 'Registration Charge: '.$memberCharge->charge_name;

        // Resolve the income GL that will be credited so it can be stored on the transaction
        // and used to debit the correct account if this charge is later reversed.
        $creditAccountId = $memberCharge->generalCharge?->credit_account_id;

        $transaction = Transaction::create([
            'reference' => $ref,
            'member_id' => $account->member_id,
            'type' => 'charge',
            'amount' => $chargeAmount,
            'payment_mode' => 'system',
            'deposited_by' => 'System (Registration Charge)',
            'transaction_date' => now()->toDateString(),
            'account_id' => $account->id,
            'account_type' => SavingsAccount::class,
            'narration' => $narration,
            'charge_name' => $memberCharge->charge_name,
            'gl_credit_account_id' => $creditAccountId,
            'is_reversible' => (bool) ($memberCharge->generalCharge?->is_reversible ?? true),
            'created_by' => Auth::id(),
        ]);

        $savingsJournal->postCharge($transaction, $account);

        $memberCharge->update([
            'status' => 'paid',
            'paid_at' => now(),
            'savings_account_id' => $account->id,
            'transaction_id' => $transaction->id,
        ]);

        return true;
    }

    /**
     * Post a product-scoped registration charge as a withdrawal-style
     * transaction against the member's savings account. Mirrors
     * collectCharge's GL posting shape (creates a Transaction, debits the
     * savings account, credits charge.credit_account_id via postCharge) but
     * does NOT create a MemberCharge receivable — the charge is consumed
     * immediately as part of registration.
     */
    public function postRegistrationCharge(
        SavingsAccount $account,
        int $generalChargeId,
        float $amount,
        SavingsJournalService $savingsJournal
    ): void {
        $charge = GeneralCharge::find($generalChargeId);
        if (! $charge || $amount <= 0) {
            return;
        }

        // Deduct from savings account balance
        $account->balance -= $amount;
        $account->save();

        $ref = 'REG-CHG-'.date('Ymd').'-'.mt_rand(10000, 99999);
        $narration = "Registration charge: {$charge->name}";

        $transaction = Transaction::create([
            'reference' => $ref,
            'member_id' => $account->member_id,
            'type' => 'charge',
            'amount' => $amount,
            'payment_mode' => 'system',
            'deposited_by' => 'System (Registration Charge)',
            'transaction_date' => now()->toDateString(),
            'account_id' => $account->id,
            'account_type' => SavingsAccount::class,
            'narration' => $narration,
            'charge_name' => $charge->name,
            'gl_credit_account_id' => $charge->credit_account_id,
            'is_reversible' => (bool) $charge->is_reversible,
            'created_by' => Auth::id(),
        ]);

        $savingsJournal->postCharge($transaction, $account);
    }

    /**
     * Waive a pending member charge without collecting payment.
     */
    public function waiveCharge(MemberCharge $memberCharge, string $narration = ''): bool
    {
        if ($memberCharge->status !== 'pending') {
            Log::warning("Attempted to waive a non-pending MemberCharge #{$memberCharge->id}.");

            return false;
        }

        $memberCharge->update([
            'status' => 'waived',
            'narration' => $narration ?: 'Waived by staff.',
        ]);

        return true;
    }
}
