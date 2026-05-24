<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SavingsTransferController extends Controller
{
    public function __construct(
        private readonly SavingsJournalService $journal,
    ) {}

    /**
     * List all active savings accounts for the transfer form dropdowns.
     * Returns: id, account_no, account_type, balance, member name.
     */
    public function accounts()
    {
        $accounts = SavingsAccount::with('member:id,name,member_number')
            ->where('status', 'active')
            ->orderBy('account_no')
            ->get(['id', 'member_id', 'account_no', 'account_type', 'balance',
                'consider_min_balance', 'savings_product_id'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'account_no' => $a->account_no,
                'account_type' => $a->account_type,
                'balance' => (float) $a->balance,
                'consider_min_balance' => (bool) $a->consider_min_balance,
                'member_name' => $a->member?->name ?? '—',
                'member_number' => $a->member?->member_number ?? '',
            ]);

        return response()->json(['data' => $accounts]);
    }

    /**
     * POST /savings-accounts/transfer
     * Transfers funds between two savings accounts.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_account_id' => ['required', 'integer', 'exists:tenant.savings_accounts,id'],
            'to_account_id' => ['required', 'integer', 'exists:tenant.savings_accounts,id',
                'different:from_account_id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transfer_date' => ['required', 'date'],
            'transaction_reference' => ['required', 'string', 'max:100',
                'unique:tenant.transactions,reference'],
            'narration' => ['nullable', 'string', 'max:500'],
        ], [
            'to_account_id.different' => 'The source and destination accounts must be different.',
        ]);

        $fromAccount = SavingsAccount::with('savingsProduct')->findOrFail($validated['from_account_id']);
        $toAccount = SavingsAccount::findOrFail($validated['to_account_id']);
        $amount = (float) $validated['amount'];

        // ── Minimum balance check on source account ───────────────────────────
        if ($fromAccount->consider_min_balance) {
            $minBalance = (float) ($fromAccount->savingsProduct?->minimum_balance ?? 0);
            $withdrawable = (float) $fromAccount->balance - $minBalance;

            if ($amount > $withdrawable) {
                $fmt = number_format($minBalance, 2);
                $fmtMax = number_format(max($withdrawable, 0), 2);

                return response()->json([
                    'message' => "Transfer would breach the minimum balance of UGX {$fmt}. Maximum transferable is UGX {$fmtMax}.",
                    'errors' => ['amount' => ["Maximum transferable amount is UGX {$fmtMax} (minimum balance: UGX {$fmt})."]],
                ], 422);
            }
        }

        // ── Sufficient balance check ──────────────────────────────────────────
        if ($amount > (float) $fromAccount->balance) {
            return response()->json([
                'message' => 'Insufficient balance in the source account.',
                'errors' => ['amount' => ['Transfer amount exceeds the source account balance.']],
            ], 422);
        }

        // ── Execute transfer in a DB transaction ──────────────────────────────
        DB::connection('tenant')->transaction(function () use (
            $fromAccount, $toAccount, $amount, $validated
        ) {
            $narration = $validated['narration'] ?? '';
            $date = $validated['transfer_date'];
            $ref = $validated['transaction_reference'];
            $userId = auth()->id();
            $branchId = BranchContext::actingBranchId();

            // Debit source
            $fromAccount->balance -= $amount;
            $fromAccount->save();

            // Credit destination
            $toAccount->balance += $amount;
            $toAccount->save();

            // Debit transaction on source account
            \DB::connection('tenant')->table('transactions')->insert([
                'reference' => $ref,
                'member_id' => $fromAccount->member_id,
                'type' => 'transfer_out',
                'amount' => $amount,
                'payment_mode' => 'internal_transfer',
                'deposited_by' => 'System',
                'transaction_date' => $date,
                'account_id' => $fromAccount->id,
                'account_type' => SavingsAccount::class,
                'narration' => "Transfer to {$toAccount->account_no}. {$narration}",
                'created_by' => $userId,
                'branch_id' => $branchId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Credit transaction on destination account
            \DB::connection('tenant')->table('transactions')->insert([
                'reference' => 'TRF-IN-'.substr($ref, 0, 80),
                'member_id' => $toAccount->member_id,
                'type' => 'transfer_in',
                'amount' => $amount,
                'payment_mode' => 'internal_transfer',
                'deposited_by' => 'System',
                'transaction_date' => $date,
                'account_id' => $toAccount->id,
                'account_type' => SavingsAccount::class,
                'narration' => "Transfer from {$fromAccount->account_no}. {$narration}",
                'created_by' => $userId,
                'branch_id' => $branchId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Post GL journal entry for the transfer
            $this->journal->postTransfer(
                fromAccount: $fromAccount,
                toAccount: $toAccount,
                amount: $amount,
                reference: $ref,
                date: $date,
                narration: $narration ?: null,
                actorId: $userId,
            );
        });

        return response()->json([
            'message' => 'Transfer of UGX '.number_format($amount, 2).' completed successfully.',
        ]);
    }
}
