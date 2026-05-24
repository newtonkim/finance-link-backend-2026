<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Services\LoanTopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LoanTopupController extends Controller
{
    public function __construct(
        protected LoanTopupService $topupService,
    ) {}

    /**
     * POST /api/v1/loans/{loan}/topup/eligibility
     *
     * Run the eligibility check without executing the top-up.
     */
    public function eligibility(Request $request, Loan $loan): JsonResponse
    {
        $validated = $request->validate([
            'fresh_cash_amount' => 'required|numeric|min:1',
            'requested_term' => 'required|integer|min:1',
            'topup_type' => 'required|in:consolidated,parallel',
        ]);

        $result = $this->topupService->checkEligibility(
            $loan,
            (float) $validated['fresh_cash_amount'],
            (int) $validated['requested_term'],
            $validated['topup_type'],
        );

        return response()->json(['data' => $result]);
    }

    /**
     * POST /api/v1/loans/{loan}/topup/execute
     *
     * Execute the top-up (auto-disburse or create application).
     */
    public function execute(Request $request, Loan $loan): JsonResponse
    {
        $validated = $request->validate([
            'fresh_cash_amount' => 'required|numeric|min:1',
            'requested_term' => 'required|integer|min:1',
            'topup_type' => 'required|in:consolidated,parallel',
        ]);

        $staffId = $request->user()?->id ?? auth()->id();

        if (! $staffId) {
            return response()->json(['message' => 'Authenticated user required.'], 401);
        }

        try {
            $result = $this->topupService->execute(
                $loan,
                (float) $validated['fresh_cash_amount'],
                (int) $validated['requested_term'],
                $validated['topup_type'],
                (int) $staffId,
            );

            return response()->json(['data' => $result]);
        } catch (\Throwable $e) {
            Log::error('Top-up execution failed: '.$e->getMessage(), [
                'exception' => $e,
                'loan_id' => $loan->id,
            ]);

            return response()->json([
                'message' => 'Failed to process top-up.',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
}
