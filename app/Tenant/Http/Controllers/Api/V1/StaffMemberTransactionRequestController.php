<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Support\BranchContext;
use App\Tenant\Modules\Savings\Services\SavingsTransactionPostingService;
use App\Tenant\Modules\Transactions\Models\MemberTransactionRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffMemberTransactionRequestController extends Controller
{
    public function __construct(private readonly SavingsTransactionPostingService $postingService) {}

    public function index(Request $request)
    {
        $query = $this->applyBranchScope(MemberTransactionRequest::query())
            ->with(['member', 'savingsAccount', 'reviewer'])
            ->orderByDesc('created_at');

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($memberId = $request->input('member_id')) {
            $query->where('member_id', $memberId);
        }

        if ($accountId = $request->input('savings_account_id')) {
            $query->where('savings_account_id', $accountId);
        }

        return response()->json($query->paginate(15)->withQueryString());
    }

    public function approve(Request $request, MemberTransactionRequest $transactionRequest): JsonResponse
    {
        $validated = $request->validate([
            'review_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var Staff $staff */
        $staff = $request->user();

        $approved = DB::connection('tenant')->transaction(function () use ($transactionRequest, $validated, $staff) {
            $lockedRequest = $this->applyBranchScope(MemberTransactionRequest::query())
                ->with(['member', 'savingsAccount'])
                ->whereKey($transactionRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== MemberTransactionRequest::STATUS_PENDING) {
                return null;
            }

            $data = [
                'deposit_date' => $lockedRequest->requested_date->format('Y-m-d'),
                'amount' => $lockedRequest->amount,
                'payment_mode' => $lockedRequest->payment_mode,
                'deposited_by' => $lockedRequest->member?->name,
                'narration' => $lockedRequest->narration,
            ];

            $transaction = $lockedRequest->type === MemberTransactionRequest::TYPE_DEPOSIT
                ? $this->postingService->deposit($lockedRequest->savingsAccount, $data, $staff->id)
                : $this->postingService->withdraw($lockedRequest->savingsAccount, $data, $staff->id);

            $lockedRequest->update([
                'status' => MemberTransactionRequest::STATUS_APPROVED,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'review_reason' => $validated['review_reason'] ?? null,
                'linked_transaction_id' => $transaction->id,
                'receipt_number' => $transaction->receipt_number,
                'transaction_reference' => $transaction->reference,
            ]);

            return $lockedRequest->fresh(['member', 'savingsAccount', 'reviewer', 'linkedTransaction']);
        });

        if (! $approved) {
            return response()->json([
                'message' => 'Only pending transaction requests can be approved.',
            ], 409);
        }

        return response()->json([
            'message' => 'Transaction request approved successfully.',
            'data' => $approved,
        ]);
    }

    public function reject(Request $request, MemberTransactionRequest $transactionRequest): JsonResponse
    {
        $validated = $request->validate([
            'review_reason' => ['required', 'string', 'max:1000'],
        ]);

        /** @var Staff $staff */
        $staff = $request->user();

        $rejected = DB::connection('tenant')->transaction(function () use ($transactionRequest, $validated, $staff) {
            $lockedRequest = $this->applyBranchScope(MemberTransactionRequest::query())
                ->whereKey($transactionRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRequest->status !== MemberTransactionRequest::STATUS_PENDING) {
                return null;
            }

            $lockedRequest->update([
                'status' => MemberTransactionRequest::STATUS_REJECTED,
                'reviewed_by' => $staff->id,
                'reviewed_at' => now(),
                'review_reason' => $validated['review_reason'],
            ]);

            return $lockedRequest->fresh(['member', 'savingsAccount', 'reviewer']);
        });

        if (! $rejected) {
            return response()->json([
                'message' => 'Only pending transaction requests can be rejected.',
            ], 409);
        }

        return response()->json([
            'message' => 'Transaction request rejected successfully.',
            'data' => $rejected,
        ]);
    }

    private function applyBranchScope(Builder $query): Builder
    {
        $staff = BranchContext::getStaff();

        if (! $staff instanceof Staff) {
            return $query->whereRaw('1 = 0');
        }

        if (BranchContext::scopeFor($staff) === BranchContext::SCOPE_ALL) {
            return $query;
        }

        $allowedBranchIds = BranchContext::allowedBranchIds();

        if (empty($allowedBranchIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $branchQuery) use ($allowedBranchIds) {
            $branchQuery
                ->whereHas('savingsAccount', function (Builder $accountQuery) use ($allowedBranchIds) {
                    $accountQuery->whereIn('branch_id', $allowedBranchIds);
                })
                ->orWhere(function (Builder $fallbackQuery) use ($allowedBranchIds) {
                    $fallbackQuery
                        ->whereHas('savingsAccount', function (Builder $accountQuery) {
                            $accountQuery->whereNull('branch_id');
                        })
                        ->whereHas('member', function (Builder $memberQuery) use ($allowedBranchIds) {
                            $memberQuery->whereIn('branch_id', $allowedBranchIds);
                        });
                });
        });
    }
}
