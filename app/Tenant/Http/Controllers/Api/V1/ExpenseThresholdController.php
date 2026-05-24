<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateExpenseThresholdsRequest;
use App\Tenant\Modules\Expenses\Contracts\ExpenseThresholdServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class ExpenseThresholdController extends Controller
{
    public function __construct(
        protected ExpenseThresholdServiceInterface $thresholdService
    ) {}

    /**
     * GET /api/v1/tenant/expenses/thresholds
     */
    public function index(): JsonResponse
    {
        $thresholds = $this->thresholdService->getThresholds();

        return Response::json([
            'data' => $thresholds,
            'message' => 'Expense thresholds retrieved successfully.'
        ]);
    }

    /**
     * PUT /api/v1/tenant/expenses/thresholds
     */
    public function update(UpdateExpenseThresholdsRequest $request): JsonResponse
    {
        $this->thresholdService->updateThresholds($request->validated('thresholds'));

        return Response::json([
            'message' => 'Expense approval thresholds updated successfully.',
            'data' => $this->thresholdService->getThresholds()
        ]);
    }

    /**
     * GET /api/v1/tenant/expenses/thresholds/path
     */
    public function showPath(Request $request): JsonResponse
    {
        $amount = (float) $request->query('amount', 0);
        $isOverBudget = filter_var($request->query('is_over_budget', false), FILTER_VALIDATE_BOOLEAN);
        
        // We use the governance service for the actual logic
        $governanceService = app(\App\Tenant\Modules\Expenses\Services\ExpenseGovernanceService::class);
        $path = $governanceService->getApprovalPath($amount, $isOverBudget);

        return Response::json([
            'data' => $path,
            'message' => 'Approval path retrieved successfully.'
        ]);
    }
}
