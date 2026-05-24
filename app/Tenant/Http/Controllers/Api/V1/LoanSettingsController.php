<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateLoanSettingsRequest;
use App\Support\BranchContext;
use App\Tenant\Modules\Settings\Models\LoanSetting;
use Illuminate\Database\QueryException;

class LoanSettingsController extends Controller
{
    /**
     * Get the loan settings for the current branch.
     */
    public function show()
    {
        $branchId = BranchContext::actingBranchId();

        if (! $branchId) {
            return response()->json(['message' => 'Branch context is required.'], 400);
        }

        $settings = LoanSetting::currentForBranch($branchId);

        return response()->json(['data' => $settings]);
    }

    /**
     * Update the loan settings for the current branch.
     */
    public function update(UpdateLoanSettingsRequest $request)
    {
        $branchId = BranchContext::actingBranchId();

        if (! $branchId) {
            return response()->json(['message' => 'Branch context is required.'], 400);
        }

        $validated = $request->validated();
        $settings = LoanSetting::currentForBranch($branchId);

        try {
            $settings->fill($validated);
            $settings->save();
        } catch (QueryException $e) {
            if (! LoanSetting::isRepaymentAllocationOrderMissingColumnError($e)) {
                throw $e;
            }

            // Backward compatibility for tenants not yet migrated.
            unset($validated['repayment_allocation_order']);
            $settings->fill($validated);
            $settings->save();
        }

        return response()->json([
            'message' => 'Loan settings updated successfully.',
            'data' => $settings,
        ]);
    }
}
