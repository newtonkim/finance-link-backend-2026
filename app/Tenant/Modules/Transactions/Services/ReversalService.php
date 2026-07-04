<?php

namespace App\Tenant\Modules\Transactions\Services;

use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use App\Tenant\Modules\Transactions\Models\Transaction;
use App\Tenant\Modules\Transactions\Models\TransactionReversal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ReversalService
{
    public function __construct(
        protected SavingsJournalService $savingsJournal,
    ) {}

    /**
     * Request a reversal. If approval is not required, immediately applies it.
     * Returns the TransactionReversal record.
     */
    public function requestReversal(Transaction $transaction, Staff $requestedBy, string $narration, ?int $assignedApproverId = null): TransactionReversal
    {
        $this->guardAgainstInvalidReversal($transaction);

        $reversal = TransactionReversal::create([
            'transaction_id' => $transaction->id,
            'status' => 'pending',
            'narration' => $narration,
            'requested_by' => $requestedBy->id,
            'assigned_approver_id' => $assignedApproverId,
        ]);

        $settings = OnboardingSettings::current();

        if (! $settings->reversal_requires_approval) {
            $this->applyReversal($reversal, $requestedBy);
        }

        return $reversal->fresh(['transaction', 'requestedBy']);
    }

    /**
     * Approve a pending reversal and apply it immediately.
     */
    public function approveReversal(TransactionReversal $reversal, Staff $approver): TransactionReversal
    {
        if (! $reversal->isPending()) {
            throw ValidationException::withMessages([
                'reversal' => ['This reversal is not pending — it has already been '.$reversal->status.'.'],
            ]);
        }

        $this->applyReversal($reversal, $approver);

        return $reversal->fresh(['transaction', 'requestedBy', 'approvedBy']);
    }

    /**
     * Reject a pending reversal.
     */
    public function rejectReversal(TransactionReversal $reversal, Staff $approver, string $reason): TransactionReversal
    {
        if (! $reversal->isPending()) {
            throw ValidationException::withMessages([
                'reversal' => ['This reversal is not pending — it has already been '.$reversal->status.'.'],
            ]);
        }

        $reversal->update([
            'status' => 'rejected',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $reversal->fresh(['transaction', 'requestedBy', 'approvedBy']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Execute the actual reversal: mirror transactions + reverse GL + adjust balance.
     * Identical logic to TransactionController::reverse() but driven by the reversal record.
     */
    private function applyReversal(TransactionReversal $reversal, Staff $actor): void
    {
        $transaction = $reversal->transaction;

        // Re-check in case it was reversed between request and approval
        $this->guardAgainstInvalidReversal($transaction);

        DB::connection('tenant')->transaction(function () use ($reversal, $transaction, $actor) {
            $rootReceipt = $transaction->receipt_number;
            $rootReference = $transaction->grouped_with ?? $transaction->reference;
            $groupReceipt = $rootReceipt ?? $transaction->umbrella_code ?? $rootReference;

            if ($rootReceipt) {
                $group = Transaction::where('receipt_number', $rootReceipt)
                    ->where('is_reversed', false)
                    ->where('account_id', $transaction->account_id)
                    ->orderBy('id')
                    ->get();
            } elseif ($transaction->umbrella_code) {
                $group = Transaction::where('umbrella_code', $transaction->umbrella_code)
                    ->where('is_reversed', false)
                    ->where('account_id', $transaction->account_id)
                    ->orderBy('id')
                    ->get();
            } else {
                $group = Transaction::where(function ($q) use ($rootReference) {
                    $q->where('reference', $rootReference)
                        ->orWhere('grouped_with', $rootReference);
                })
                    ->where('is_reversed', false)
                    ->where('account_id', $transaction->account_id)
                    ->orderBy('id')
                    ->get();
            }

            if ($group->isEmpty()) {
                $group = collect([$transaction]);
            }

            // Net balance delta.
            // The `account_type` column is overloaded with transaction-type labels
            // (e.g. 'deposit') that the morph map points at Transaction, so the
            // `account` relation can resolve to the wrong model. Resolve the real
            // savings account by id whenever the morph is not a SavingsAccount.
            $account = $transaction->account instanceof SavingsAccount
                ? $transaction->account
                : SavingsAccount::find($transaction->account_id);
            $netDelta = 0.0;

            foreach ($group as $txn) {
                $netDelta += match ($txn->type) {
                    'deposit' => -(float) $txn->amount,
                    'withdrawal' => +(float) $txn->amount,
                    'withdraw' => +(float) $txn->amount,
                    'charge' => +(float) $txn->amount,
                    'deposit-charge' => $this->transactionAmount($txn),
                    'deposit-Charge' => $this->transactionAmount($txn),
                    'withdrawal-charge' => $this->transactionAmount($txn),
                    'withdraw-charge' => $this->transactionAmount($txn),
                    default => 0.0,
                };
            }

            if ($account && $netDelta !== 0.0) {
                $account->balance = (float) $account->balance + $netDelta;
                $account->save();
            }

            $rootReversal = null;
            $firstReversalId = null;

            foreach ($group as $txn) {
                $txn->update(['is_reversed' => true]);

                $reversalRef = 'REV-'.strtoupper(substr(uniqid(), -8));

                if ($rootReversal === null) {
                    $rootReversal = $reversalRef;
                }

                $reversalTxn = Transaction::create([
                    'reference' => $reversalRef,
                    'umbrella_code' => $txn->umbrella_code,
                    'receipt_number' => $groupReceipt,
                    'member_id' => $txn->member_id,
                    'type' => 'reversal',
                    'amount' => $this->transactionAmount($txn),
                    'payment_mode' => $txn->payment_mode,
                    'deposited_by' => $actor->name,
                    'transaction_date' => now()->toDateString(),
                    'account_id' => $txn->account_id,
                    'account_type' => $txn->account_type,
                    'narration' => $this->buildNarration($txn, $reversal->narration),
                    'reversal_of' => $txn->id,
                    'grouped_with' => ($reversalRef === $rootReversal) ? null : $rootReversal,
                    'created_by' => $actor->id,
                ]);

                $this->postReversalAccounting($txn, $reversalRef);

                if ($firstReversalId === null) {
                    $firstReversalId = $reversalTxn->id;
                }
            }

            // Link the reversal record to the primary reversal transaction
            $reversal->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'reversal_transaction_id' => $firstReversalId,
            ]);
        });
    }

    private function guardAgainstInvalidReversal(Transaction $transaction): void
    {
        if ($transaction->is_reversed) {
            throw ValidationException::withMessages([
                'transaction' => ['This transaction has already been reversed.'],
            ]);
        }

        if ($transaction->type === 'reversal') {
            throw ValidationException::withMessages([
                'transaction' => ['A reversal entry cannot itself be reversed.'],
            ]);
        }

        if ($transaction->is_reversible === false) {
            throw ValidationException::withMessages([
                'transaction' => ['This transaction is marked as non-reversible.'],
            ]);
        }

        $settings = OnboardingSettings::current();

        if ($settings->reversal_max_days > 0) {
            $txnDate = $transaction->transaction_date ?? $transaction->created_at->toDateString();
            $daysSince = now()->diffInDays($txnDate, true);

            if ($daysSince > $settings->reversal_max_days) {
                throw ValidationException::withMessages([
                    'transaction' => [
                        "The reversal window has expired. Reversals are only allowed within {$settings->reversal_max_days} day(s) of the transaction date.",
                    ],
                ]);
            }
        }
    }

    private function buildNarration(Transaction $txn, string $userNarration): string
    {
        $typeLabel = ucfirst($txn->type);
        $date = $txn->transaction_date?->format('Y-m-d') ?? '';

        return "Reversal of {$typeLabel} — Ref: {$txn->reference} on {$date} | Reason: {$userNarration}";
    }

    private function postReversalAccounting(Transaction $txn, string $reversalRef): void
    {
        $originalJe = JournalEntry::where('reference', $txn->reference)
            ->where('status', 'posted')
            ->first();

        if ($originalJe) {
            $this->savingsJournal->reverseJournalEntry(
                original: $originalJe,
                reversalReference: $reversalRef,
                date: now()->toDateString(),
                reversedBy: auth()->id(),
                narration: "Reversal of {$txn->type} txno:{$txn->reference}",
            );

            return;
        }

        if ($txn->type === 'charge' && (float) $txn->amount <= 0) {
            return;
        }

        $savingsAccount = SavingsAccount::find($txn->account_id);
        $reversalTxn = Transaction::where('reference', $reversalRef)->first();

        if (! $savingsAccount || ! $reversalTxn) {
            Log::warning("postReversalAccounting: missing account or reversal transaction for ref {$reversalRef}");

            return;
        }

        match ($txn->type) {
            'deposit' => $this->savingsJournal->postWithdrawal($reversalTxn, $savingsAccount),
            'withdrawal' => $this->savingsJournal->postDeposit($reversalTxn, $savingsAccount),
            'withdraw' => $this->savingsJournal->postDeposit($reversalTxn, $savingsAccount),
            'charge' => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
            'deposit-charge' => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
            'deposit-Charge' => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
            'withdrawal-charge' => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
            'withdraw-charge' => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
            default => null,
        };
    }

    private function transactionAmount(Transaction $transaction): float
    {
        $amount = (float) ($transaction->amount ?? 0);

        if ($amount > 0) {
            return $amount;
        }

        return (float) ($transaction->charge_amount ?? 0);
    }
}
