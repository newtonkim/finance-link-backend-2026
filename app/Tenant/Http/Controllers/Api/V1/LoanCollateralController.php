<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Http\Resources\LoanApplicationCollateralResource;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationCollateral;
use App\Tenant\Support\TenantMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LoanCollateralController extends Controller
{
    public function index(LoanApplication $loanApplication): JsonResponse
    {
        $items = $loanApplication->collaterals()->orderBy('created_at')->get();

        $totalValue = $items->sum('estimated_value');

        return response()->json([
            'data' => LoanApplicationCollateralResource::collection($items)->resolve(),
            'total_value' => (float) $totalValue,
            'total_value_formatted' => TenantMoney::format($totalValue),
        ]);
    }

    public function store(Request $request, LoanApplication $loanApplication): JsonResponse
    {
        $data = $request->validate([
            'asset_type' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'estimated_value' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'proof_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        if ($request->hasFile('proof_file')) {
            $data['proof_path'] = $request->file('proof_file')
                ->store("collateral/{$loanApplication->id}", 'public');
        }

        unset($data['proof_file']);

        $item = $loanApplication->collaterals()->create($data);

        return response()->json([
            'message' => 'Collateral item added.',
            'data' => new LoanApplicationCollateralResource($item),
        ], 201);
    }

    public function destroy(LoanApplication $loanApplication, LoanApplicationCollateral $collateral): JsonResponse
    {
        abort_if($collateral->loan_application_id !== $loanApplication->id, 404);

        if ($collateral->proof_path) {
            Storage::disk('public')->delete($collateral->proof_path);
        }

        $collateral->delete();

        return response()->json(['message' => 'Collateral item removed.']);
    }
}
