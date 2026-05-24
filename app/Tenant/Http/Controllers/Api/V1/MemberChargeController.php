<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Members\Models\MemberCharge;
use App\Tenant\Modules\Members\Services\MemberChargeService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberChargeController extends Controller
{
    public function __construct(
        protected MemberChargeService $memberChargeService,
        protected SavingsJournalService $savingsJournal,
    ) {}

    /**
     * List all charges for a member (pending, paid, waived).
     */
    public function index(Member $member, Request $request)
    {
        $request->validate([
            'status' => ['nullable', 'in:pending,paid,waived'],
        ]);

        $query = MemberCharge::where('member_id', $member->id)
            ->with(['generalCharge', 'transaction'])
            ->orderBy('applied_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $charges = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $charges->map(fn (MemberCharge $c) => [
                'id' => $c->id,
                'charge_name' => $c->charge_name,
                'amount' => $c->amount,
                'status' => $c->status,
                'due_date' => $c->due_date?->toDateString(),
                'applied_at' => $c->applied_at?->toDateTimeString(),
                'paid_at' => $c->paid_at?->toDateTimeString(),
                'narration' => $c->narration,
                'general_charge_id' => $c->general_charge_id,
                'savings_account_id' => $c->savings_account_id,
                'transaction_id' => $c->transaction_id,
                'transaction' => $c->transaction ? [
                    'reference' => $c->transaction->reference,
                ] : null,
            ]),
            'meta' => [
                'current_page' => $charges->currentPage(),
                'last_page' => $charges->lastPage(),
                'total' => $charges->total(),
            ],
        ]);
    }

    /**
     * Waive a pending charge for a member.
     */
    public function waive(Member $member, MemberCharge $memberCharge, Request $request)
    {
        if ((int) $memberCharge->member_id !== (int) $member->id) {
            return response()->json(['message' => 'Charge does not belong to this member.'], 403);
        }

        $validated = $request->validate([
            'narration' => ['nullable', 'string', 'max:500'],
        ]);

        $waived = $this->memberChargeService->waiveCharge(
            $memberCharge,
            $validated['narration'] ?? ''
        );

        if (! $waived) {
            return response()->json(['message' => 'Only pending charges can be waived.'], 422);
        }

        return response()->json(['message' => 'Charge waived successfully.']);
    }

    /**
     * Manually collect a pending charge from the member's savings account.
     */
    public function collect(Member $member, MemberCharge $memberCharge, Request $request)
    {
        if ((int) $memberCharge->member_id !== (int) $member->id) {
            return response()->json(['message' => 'Charge does not belong to this member.'], 403);
        }

        if ($memberCharge->status !== 'pending') {
            return response()->json(['message' => 'Only pending charges can be collected.'], 422);
        }

        $validated = $request->validate([
            'savings_account_id' => ['required', 'exists:tenant.savings_accounts,id'],
        ]);

        $account = SavingsAccount::find($validated['savings_account_id']);

        if ((int) $account->member_id !== (int) $member->id) {
            return response()->json(['message' => 'Savings account does not belong to this member.'], 403);
        }

        $collected = DB::connection('tenant')->transaction(function () use ($memberCharge, $account) {
            return $this->memberChargeService->collectCharge($memberCharge, $account, $this->savingsJournal);
        });

        if (! $collected) {
            return response()->json([
                'message' => 'Unable to collect charge. Insufficient balance or charge is not pending.',
            ], 422);
        }

        return response()->json(['message' => 'Charge collected successfully.']);
    }
}
