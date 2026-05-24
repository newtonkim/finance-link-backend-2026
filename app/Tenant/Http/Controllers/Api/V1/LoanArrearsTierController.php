<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Loans\Models\LoanArrearsTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoanArrearsTierController extends Controller
{
    /**
     * Fetch all active tiers.
     */
    public function index(): JsonResponse
    {
        $tiers = LoanArrearsTier::orderBy('from_day')->get();

        return response()->json([
            'status' => 'success',
            'data' => $tiers,
        ]);
    }

    /**
     * Replace all tiers.
     * Since the frontend drawer shows 4 fixed ranges, it's easier to bulk sync them.
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $request->validate([
            'tiers' => 'required|array',
            'tiers.*.from_day' => 'required|integer|min:0',
            'tiers.*.to_day' => 'nullable|integer|gt:tiers.*.from_day',
            'tiers.*.charge_type' => 'required|in:flat,percentage',
            'tiers.*.charge_value' => 'required|numeric|min:0',
            'tiers.*.applies_to' => 'required|in:outstanding_balance,principal_due,installment_due',
            'tiers.*.is_active' => 'required|boolean',
        ]);

        DB::connection('tenant')->transaction(function () use ($request) {
            // First clear existing tiers to avoid overlap conflicts during creation
            LoanArrearsTier::query()->delete();

            foreach ($request->input('tiers') as $tierData) {
                LoanArrearsTier::create([
                    'from_day' => $tierData['from_day'],
                    'to_day' => $tierData['to_day'],
                    'charge_type' => $tierData['charge_type'],
                    'charge_value' => $tierData['charge_value'],
                    'applies_to' => $tierData['applies_to'],
                    'is_active' => $tierData['is_active'],
                ]);
            }
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Arrears tiers updated successfully.',
            'data' => LoanArrearsTier::orderBy('from_day')->get(),
        ]);
    }
}
