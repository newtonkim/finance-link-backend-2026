<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Services\SavingsTransactionPostingService;
use App\Tenant\Modules\Transactions\Models\MemberTransactionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberTransactionRequestController extends Controller
{
    public function __construct(private readonly SavingsTransactionPostingService $postingService) {}

    public function index(Request $request)
    {
        /** @var Member $member */
        $member = $request->user();

        $query = MemberTransactionRequest::query()
            ->with(['savingsAccount'])
            ->where('member_id', $member->id)
            ->orderByDesc('created_at');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        return response()->json($query->paginate(15)->withQueryString());
    }

    public function storeDeposit(Request $request): JsonResponse
    {
        return $this->store($request, MemberTransactionRequest::TYPE_DEPOSIT);
    }

    public function storeWithdrawal(Request $request): JsonResponse
    {
        return $this->store($request, MemberTransactionRequest::TYPE_WITHDRAWAL);
    }

    private function store(Request $request, string $type): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();

        $validated = $request->validate([
            'savings_account_id' => ['required', 'integer', 'exists:tenant.savings_accounts,id'],
            'requested_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_mode' => ['required', 'string', 'max:50'],
            'narration' => ['nullable', 'string', 'max:1000'],
        ]);

        $account = SavingsAccount::query()
            ->with(['member', 'savingsProduct'])
            ->where('member_id', $member->id)
            ->whereKey($validated['savings_account_id'])
            ->firstOrFail();

        $this->postingService->assertCanTransact($account, $type);

        if ($type === MemberTransactionRequest::TYPE_WITHDRAWAL) {
            $this->postingService->assertCanWithdraw($account, (float) $validated['amount']);
        }

        $transactionRequest = MemberTransactionRequest::create([
            'member_id' => $member->id,
            'savings_account_id' => $account->id,
            'type' => $type,
            'amount' => $validated['amount'],
            'payment_mode' => $validated['payment_mode'],
            'narration' => $validated['narration'] ?? null,
            'requested_date' => $validated['requested_date'],
            'status' => MemberTransactionRequest::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => ucfirst($type).' request submitted successfully.',
            'data' => $transactionRequest->load(['savingsAccount']),
        ], 201);
    }
}
