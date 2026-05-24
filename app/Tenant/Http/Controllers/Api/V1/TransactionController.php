<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    public function __construct(
        protected SavingsJournalService $savingsJournal,
    ) {}

    /**
     * Reverse a transaction and all transactions grouped with it.
     *
     * When a deposit has associated charges (linked via grouped_with = deposit reference),
     * clicking reverse on either the deposit or a charge will atomically reverse the entire
     * group: the deposit + all its charges. This produces one reversal transaction per
     * original transaction, keeping a clean 1:1 audit trail.
     *
     * Balance impact is applied once per account by summing the net effect of the whole
     * group before saving — avoids stale-read issues when multiple transactions share
     * the same account.
     */
    public function reverse(Transaction $transaction): JsonResponse
    {
        if ($transaction->is_reversed) {
            return response()->json(['message' => 'This transaction has already been reversed.'], 422);
        }

        if ($transaction->type === 'reversal') {
            return response()->json(['message' => 'A reversal entry cannot itself be reversed.'], 422);
        }

        if ($transaction->is_reversible === false) {
            return response()->json(['message' => 'This transaction is marked as non-reversible and cannot be reversed.'], 422);
        }

        return DB::connection('tenant')->transaction(function () use ($transaction): JsonResponse {
            $rootReceipt = $transaction->receipt_number;
            $rootReference = $transaction->grouped_with ?? $transaction->reference;

            if ($rootReceipt) {
                $group = Transaction::where('receipt_number', $rootReceipt)
                    ->where('is_reversed', false)
                    ->where('account_id', $transaction->account_id)
                    ->orderBy('id')
                    ->get();
            } else {
                // Legacy grouping: root reference with charges grouped via grouped_with.
                $group = Transaction::where(function ($q) use ($rootReference) {
                    $q->where('reference', $rootReference)
                        ->orWhere('grouped_with', $rootReference);
                })
                    ->where('is_reversed', false)
                    ->where('account_id', $transaction->account_id)
                    ->orderBy('id')
                    ->get();
            }

            // Edge case: orphan transaction not found via group lookup
            if ($group->isEmpty()) {
                $group = collect([$transaction]);
            }

            // Calculate net balance delta for the account in one pass
            $account = $transaction->account;
            $netDelta = 0.0;

            foreach ($group as $txn) {
                $netDelta += match ($txn->type) {
                    'deposit' => -(float) $txn->amount,
                    'withdrawal' => +(float) $txn->amount,
                    'deposit-charge' => -(float) $txn->charge_amount,
                    'withdrawal-charge' => +(float) $txn->charge_amount,
                    'charge' => +(float) $txn->amount,
                    default => 0.0,
                };
            }

            if ($account && $netDelta !== 0.0) {
                $account->balance = (float) $account->balance + $netDelta;
                $account->save();
            }

            $rootReversal = null;
            $reversals = [];

            foreach ($group as $txn) {
                $txn->update(['is_reversed' => true]);

                $reversalRef = 'REV-'.strtoupper(substr(uniqid(), -8));

                if ($rootReversal === null) {
                    $rootReversal = $reversalRef;
                }

                $reversal = Transaction::create([
                    'reference' => $reversalRef,
                    'umbrella_code' => $txn->umbrella_code,
                    'receipt_number' => $rootReceipt ?? $rootReference,
                    'member_id' => $txn->member_id,
                    'type' => 'reversal',
                    'amount' => $txn->amount??$txn->charge_amount,
                    'payment_mode' => $txn->payment_mode,
                    'deposited_by' => optional(auth()->user())->name ?? 'System',
                    'transaction_date' => now()->toDateString(),
                    'account_id' => $txn->account_id,
                    'account_type' => $txn->account_type,
                    'narration' => $this->buildReversalNarration($txn),
                    'reversal_of' => $txn->id,
                    'grouped_with' => ($reversalRef === $rootReversal) ? null : $rootReversal,
                    'created_by' => optional(auth()->user())->id,
                ]);

                $this->postReversalAccounting($txn, $reversalRef);

                $reversal->load('account');
                $reversals[] = $this->formatReversal($reversal);
            }

            return response()->json([
                'message' => 'Transaction reversed successfully.',
                'reversal' => $reversals[0] ?? null,
                'reversals' => $reversals,
            ]);
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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

        // Fallback: original JE not found — synthesise a correcting entry.
        $savingsAccount = SavingsAccount::find($txn->account_id);
        $reversalTxn = Transaction::where('reference', $reversalRef)->first();

        if (! $savingsAccount || ! $reversalTxn) {
            Log::warning("postReversalAccounting: missing account or reversal transaction for ref {$reversalRef}");

            return;
        }

        match ($txn->type) {
            'deposit' => $this->savingsJournal->postWithdrawal($reversalTxn, $savingsAccount),
            'withdrawal' => $this->savingsJournal->postDeposit($reversalTxn, $savingsAccount),
            'charge' => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
            default => null,
        };
    }

    private function buildReversalNarration(Transaction $txn): string
    {
        $typeLabel = ucfirst($txn->type);
        $date = $txn->transaction_date?->format('Y-m-d') ?? '';
        $narration = $txn->narration ? " | {$txn->narration}" : '';

        return "Reversal of {$typeLabel} — Ref: {$txn->reference}{$narration} on {$date}";
    }

    private function formatReversal(Transaction $reversal): array
    {
        return [
            'id' => $reversal->id,
            'reference' => $reversal->reference,
            'receipt_number' => $reversal->receipt_number,
            'amount' => $reversal->amount,
            'type' => $reversal->type,
            'narration' => $reversal->narration,
            'transaction_date' => $reversal->transaction_date?->format('Y-m-d'),
            'created_at' => $reversal->created_at?->format('Y-m-d H:i:s'),
            'deposited_by' => $reversal->deposited_by,
            'payment_mode' => $reversal->payment_mode,
            'is_reversed' => false,
            'reversal_of' => $reversal->reversal_of,
            'grouped_with' => $reversal->grouped_with,
            'account' => $reversal->account ? [
                'id' => $reversal->account->id,
                'account_no' => $reversal->account->account_no,
                'account_type' => $reversal->account->account_type,
            ] : null,
        ];
    }
}
