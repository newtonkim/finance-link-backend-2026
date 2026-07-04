<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Tenant\Http\Resources\SavingsAccountResource;
use App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use App\Tenant\Support\TenantMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberPortalController extends Controller
{
    public function __construct(private readonly SavingsAccountStatementServiceInterface $statementService) {}

    public function accounts(Request $request)
    {
        /** @var Member $member */
        $member = $request->user();

        $query = SavingsAccount::query()
            ->with(['savingsProduct'])
            ->where('member_id', $member->id)
            ->orderByDesc('created_at');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return SavingsAccountResource::collection($query->paginate(15)->withQueryString());
    }

    public function account(Request $request, int $id): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();

        $account = $this->findMemberAccount($member, $id);

        return response()->json([
            'data' => new SavingsAccountResource($account->load(['member', 'savingsProduct'])),
        ]);
    }

    public function accountStatement(Request $request, int $id): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();

        $this->findMemberAccount($member, $id);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return response()->json($this->statementService->buildStatement(
            $id,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        ));
    }

    public function statement(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();

        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $accountIds = SavingsAccount::query()
            ->where('member_id', $member->id)
            ->pluck('id');

        $query = Transaction::query()
            ->where(function ($q) use ($member, $accountIds) {
                $q->where('member_id', $member->id)
                    ->orWhereIn('account_id', $accountIds);
            })
            ->whereNull('deleted_at');

        if (! empty($validated['date_from'])) {
            $query->whereDate('transaction_date', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('transaction_date', '<=', $validated['date_to']);
        }

        $transactions = $query->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $transactions->getCollection()->transform(fn (Transaction $transaction) => [
            'id' => $transaction->id,
            'transaction_date' => $transaction->transaction_date?->format('Y-m-d'),
            'type' => $transaction->type,
            'amount' => $transaction->amount,
            'amount_formatted' => TenantMoney::format($transaction->amount),
            'charge_amount' => $transaction->charge_amount,
            'payment_mode' => $transaction->payment_mode,
            'reference' => $transaction->reference,
            'receipt_number' => $transaction->receipt_number,
            'account_id' => $transaction->account_id,
            'narration' => $transaction->narration,
        ]);

        $totalSavings = SavingsAccount::query()
            ->where('member_id', $member->id)
            ->sum('balance');

        return response()->json([
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'member_number' => $member->member_number,
                'status' => $member->status,
            ],
            'summary' => [
                'total_savings' => (float) $totalSavings,
                'total_savings_formatted' => TenantMoney::format($totalSavings),
                'currency_code' => TenantMoney::code(),
            ],
            'transactions' => $transactions,
        ]);
    }

    private function findMemberAccount(Member $member, int $id): SavingsAccount
    {
        return SavingsAccount::query()
            ->where('member_id', $member->id)
            ->whereKey($id)
            ->firstOrFail();
    }
}
