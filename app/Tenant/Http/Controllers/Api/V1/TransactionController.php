<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Settings\Models\SaccoBranding;
use App\Tenant\Modules\Transactions\Models\Transaction;
use App\Tenant\Support\TenantMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
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
                    'withdraw' => +(float) $txn->amount,
                    'deposit-charge' => $this->transactionAmount($txn),
                    'deposit-Charge' => $this->transactionAmount($txn),
                    'withdrawal-charge' => $this->transactionAmount($txn),
                    'withdraw-charge' => $this->transactionAmount($txn),
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
                    'receipt_number' => $groupReceipt,
                    'member_id' => $txn->member_id,
                    'type' => 'reversal',
                    'amount' => $this->transactionAmount($txn),
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

    /**
     * Receipt payload for a transaction and every charge grouped with it.
     *
     * The frontend can call this from the Receipt column for either the main
     * deposit/withdrawal row or one of its charge rows. Grouping is resolved by
     * receipt number first, then legacy umbrella/grouped references.
     */
    public function receipt(Transaction $transaction): JsonResponse
    {
        $transaction->loadMissing(['member', 'account']);

        $group = $this->receiptGroup($transaction);
        $main = $this->mainReceiptTransaction($group) ?? $transaction;
        $charges = $group->filter(fn (Transaction $txn) => $this->isChargeTransaction($txn));
        $chargeTotal = $charges->sum(fn (Transaction $txn) => $this->transactionAmount($txn));
        $mainAmount = $this->transactionAmount($main);
        $direction = $this->receiptDirection($main);

        $account = $main->account ?: $transaction->account;
        if ($account instanceof SavingsAccount) {
            $account->loadMissing('savingsProduct');
        }

        $member = $main->member ?: $transaction->member;
        $branch = $this->receiptBranch($main, $account, $member);

        return response()->json([
            'data' => [
                'receipt_number' => $main->receipt_number ?? $transaction->receipt_number ?? $main->umbrella_code ?? $transaction->umbrella_code ?? $main->reference,
                'reference' => $main->reference,
                'transaction_type' => $main->type,
                'transaction_date' => $main->transaction_date?->format('Y-m-d'),
                'created_at' => $main->created_at?->format('Y-m-d H:i:s'),
                'payment_mode' => $main->payment_mode,
                'received_by' => $main->deposited_by,
                'narration' => $main->narration,
                'currency_code' => TenantMoney::code(),
                'branding' => $this->receiptBranding(),
                'branch' => $branch,
                'member' => $member ? [
                    'id' => $member->id,
                    'name' => trim(($member->salutation ? $member->salutation.' ' : '').$member->name),
                    'member_number' => $member->member_number,
                    'code' => $member->code,
                    'phone' => $member->phone,
                ] : null,
                'account' => $account ? [
                    'id' => $account->id,
                    'account_no' => $account->account_no ?? $account->code ?? null,
                    'code' => $account->code ?? null,
                    'account_type' => $account->account_type ?? null,
                    'product' => $account instanceof SavingsAccount ? $account->savingsProduct?->name : null,
                ] : null,
                'main_transaction' => $this->formatReceiptLine($main, 'principal'),
                'charge_lines' => $charges->values()
                    ->map(fn (Transaction $txn) => $this->formatReceiptLine($txn, 'charge'))
                    ->all(),
                'lines' => $group->values()
                    ->map(fn (Transaction $txn) => $this->formatReceiptLine(
                        $txn,
                        $this->isChargeTransaction($txn) ? 'charge' : 'principal'
                    ))
                    ->all(),
                'totals' => [
                    'transaction_amount' => $mainAmount,
                    'transaction_amount_formatted' => TenantMoney::format($mainAmount),
                    'charge_total' => $chargeTotal,
                    'charge_total_formatted' => TenantMoney::format($chargeTotal),
                    'net_deposit_amount' => $direction === 'deposit' ? max($mainAmount - $chargeTotal, 0) : null,
                    'net_deposit_amount_formatted' => $direction === 'deposit' ? TenantMoney::format(max($mainAmount - $chargeTotal, 0)) : null,
                    'net_withdrawal_amount' => $direction === 'withdrawal' ? max($mainAmount - $chargeTotal, 0) : null,
                    'net_withdrawal_amount_formatted' => $direction === 'withdrawal' ? TenantMoney::format(max($mainAmount - $chargeTotal, 0)) : null,
                    'total_account_debit' => $direction === 'withdrawal' ? $mainAmount : null,
                    'total_account_debit_formatted' => $direction === 'withdrawal' ? TenantMoney::format($mainAmount) : null,
                ],
                'is_reversed' => $group->every(fn (Transaction $txn) => (bool) $txn->is_reversed),
            ],
        ]);
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

        if ($txn->type === 'charge' && (float) $txn->amount <= 0) {
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
            'withdraw' => $this->savingsJournal->postDeposit($reversalTxn, $savingsAccount),
            'charge' => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
            'deposit-charge' => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
            'deposit-Charge' => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
            'withdrawal-charge' => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
            'withdraw-charge' => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
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

    private function receiptGroup(Transaction $transaction): Collection
    {
        $query = Transaction::query()->with(['member', 'account']);

        if ($transaction->receipt_number) {
            $group = (clone $query)
                ->where('receipt_number', $transaction->receipt_number)
                ->where('account_id', $transaction->account_id)
                ->orderBy('id')
                ->get();

            if ($group->isNotEmpty()) {
                return $group;
            }
        }

        if ($transaction->umbrella_code) {
            $group = (clone $query)
                ->where('umbrella_code', $transaction->umbrella_code)
                ->where('account_id', $transaction->account_id)
                ->orderBy('id')
                ->get();

            if ($group->isNotEmpty()) {
                return $group;
            }
        }

        $rootReference = $transaction->grouped_with ?? $transaction->reference;

        $group = (clone $query)
            ->where(function ($q) use ($rootReference) {
                $q->where('reference', $rootReference)
                    ->orWhere('grouped_with', $rootReference);
            })
            ->where('account_id', $transaction->account_id)
            ->orderBy('id')
            ->get();

        return $group->isNotEmpty() ? $group : collect([$transaction]);
    }

    private function mainReceiptTransaction(Collection $group): ?Transaction
    {
        return $group->first(fn (Transaction $txn) => in_array($txn->type, ['deposit', 'withdrawal', 'withdraw'], true))
            ?? $group->first(fn (Transaction $txn) => ! $this->isChargeTransaction($txn));
    }

    private function isChargeTransaction(Transaction $transaction): bool
    {
        if (in_array($transaction->type, ['deposit', 'withdrawal', 'withdraw'], true)) {
            return false;
        }

        return $transaction->type === 'charge'
            || str_contains(strtolower((string) $transaction->type), 'charge')
            || (float) ($transaction->charge_amount ?? 0) > 0;
    }

    private function transactionAmount(Transaction $transaction): float
    {
        $amount = (float) ($transaction->amount ?? 0);

        if ($amount > 0) {
            return $amount;
        }

        return (float) ($transaction->charge_amount ?? 0);
    }

    private function receiptDirection(Transaction $transaction): ?string
    {
        return match ($transaction->type) {
            'deposit' => 'deposit',
            'withdrawal', 'withdraw' => 'withdrawal',
            default => null,
        };
    }

    private function formatReceiptLine(Transaction $transaction, string $lineType): array
    {
        $amount = $this->transactionAmount($transaction);

        return [
            'id' => $transaction->id,
            'line_type' => $lineType,
            'reference' => $transaction->reference,
            'receipt_number' => $transaction->receipt_number,
            'type' => $transaction->type,
            'description' => $lineType === 'charge'
                ? ($transaction->charge_name ?: $transaction->narration ?: 'Transaction charge')
                : ($transaction->narration ?: ucfirst((string) $transaction->type)),
            'amount' => $amount,
            'amount_formatted' => TenantMoney::format($amount),
            'payment_mode' => $transaction->payment_mode,
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
            'is_reversed' => (bool) $transaction->is_reversed,
            'reversal_of' => $transaction->reversal_of,
            'grouped_with' => $transaction->grouped_with,
        ];
    }

    private function receiptBranding(): array
    {
        $branding = SaccoBranding::current();

        return [
            'sacco_name' => $branding->sacco_name,
            'tagline' => $branding->tagline,
            'logo_path' => $branding->logo_path,
            'logo_url' => $branding->logo_path ? '/storage/'.$branding->logo_path : null,
        ];
    }

    private function receiptBranch(Transaction $transaction, mixed $account, mixed $member): ?array
    {
        $branchId = $transaction->branch_id
            ?? ($account->branch_id ?? null)
            ?? ($member->branch_id ?? null);

        if (! $branchId) {
            return null;
        }

        $branch = DB::connection('tenant')
            ->table('branches')
            ->where('id', $branchId)
            ->first(['id', 'name', 'code', 'phone', 'email', 'address']);

        return $branch ? (array) $branch : null;
    }
}
