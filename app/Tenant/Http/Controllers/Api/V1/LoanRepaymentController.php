<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\PostRepaymentRequest;
use App\Http\Requests\Tenant\SavingsRepaymentRequest;
use App\Models\Scopes\BranchReadScope;
use App\Tenant\Http\Resources\LoanTransactionResource;
use App\Tenant\Modules\Loans\Contracts\LoanRepaymentServiceInterface;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanTransaction;
use App\Tenant\Modules\Loans\Services\LoanRepaymentReversalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanRepaymentController extends Controller
{
    public function __construct(
        protected LoanRepaymentServiceInterface $service,
        protected LoanRepaymentReversalService $reversalService,
    ) {}

    /**
     * POST /loans/{id}/repayments/preview
     * Returns allocation breakdown without persisting.
     */
    public function preview(Request $request, int $id): JsonResponse
    {
        $loan = Loan::withoutGlobalScope(BranchReadScope::class)->findOrFail($id);

        $amount = (float) $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ])['amount'];

        return response()->json([
            'data' => $this->service->preview($loan, $amount),
        ]);
    }

    /**
     * POST /loans/{id}/repayments
     */
    public function store(PostRepaymentRequest $request, int $id): JsonResponse
    {
        $loan = Loan::withoutGlobalScope(BranchReadScope::class)->findOrFail($id);

        $actorId = auth('tenant')->id() ?? auth()->id() ?? 1;
        $validated = $request->validated();

        $txn = $this->service->post(
            $loan,
            (float) $validated['amount'],
            $validated,
            $actorId,
        );

        $txn->load('collectedBy');

        return response()->json([
            'message' => 'Repayment posted successfully.',
            'data' => new LoanTransactionResource($txn),
        ], 201);
    }

    /**
     * POST /loans/{id}/repayments/{transaction}/reverse
     */
    public function reverse(int $id, LoanTransaction $transaction): JsonResponse
    {
        $loan = Loan::withoutGlobalScope(BranchReadScope::class)->findOrFail($id);

        if ($transaction->loan_id !== $loan->id) {
            return response()->json(['message' => 'Transaction does not belong to this loan.'], 422);
        }

        $actorId = auth('tenant')->id() ?? auth()->id() ?? 1;

        $this->reversalService->reverse($loan, $transaction, actorId: $actorId);

        return response()->json(['message' => 'Repayment reversed successfully.']);
    }

    /**
     * POST /loans/{loan}/repay-from-savings
     */
    public function repayFromSavings(SavingsRepaymentRequest $request, Loan $loan): JsonResponse
    {
        $actorId = auth('tenant')->id() ?? auth()->id() ?? 1;

        $txn = $this->service->repayFromSavings($loan, $request->validated(), $actorId);

        $txn->load('collectedBy');

        return response()->json([
            'message' => 'Repayment from savings posted successfully.',
            'data' => new LoanTransactionResource($txn),
        ], 201);
    }
}
