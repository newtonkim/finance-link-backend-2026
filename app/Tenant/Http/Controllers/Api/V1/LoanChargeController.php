<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreLoanChargeRequest;
use App\Http\Requests\Tenant\UpdateLoanChargeRequest;
use App\Tenant\Http\Resources\LoanChargeResource;
use App\Tenant\Modules\Loans\Models\LoanAppliedCharge;
use App\Tenant\Modules\Loans\Models\LoanCharge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LoanChargeController extends Controller
{
    public function index(Request $request)
    {
        $query = LoanCharge::query();

        if ($this->hasColumn('income_account_id') && $this->hasColumn('receivable_account_id')) {
            $query->with(['incomeAccount:id,name,gl_code', 'receivableAccount:id,name,gl_code']);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                if ($this->hasColumn('name')) {
                    $q->where('name', 'like', "%{$search}%");
                }
                if ($this->hasColumn('code')) {
                    $this->hasColumn('name')
                        ? $q->orWhere('code', 'like', "%{$search}%")
                        : $q->where('code', 'like', "%{$search}%");
                }
            });
        }

        if ($this->hasColumn('category') && ($category = $request->string('category')->toString())) {
            $query->where('category', $category);
        }

        if ($this->hasColumn('is_active') && $request->has('is_active')) {
            $query->where('is_active', (bool) ((int) $request->input('is_active')));
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 15)));
        $result = $query->orderByDesc('id')->paginate($perPage);

        return LoanChargeResource::collection($result);
    }

    public function store(StoreLoanChargeRequest $request)
    {
        if (! $this->supportsDefinitionsSchema()) {
            return response()->json([
                'message' => 'Loan charge definitions schema is not ready for this tenant. Run tenant migrations and retry.',
            ], 409);
        }

        $payload = $request->validated();
        $payload['is_active'] = $payload['is_active'] ?? true;
        $payload['grace_days'] = $payload['grace_days'] ?? 0;
        $payload['max_value_type'] = $payload['max_value_type'] ?? 'none';
        $payload['code'] = $this->generateCode($payload['name']);

        if (($payload['max_value_type'] ?? 'none') === 'none') {
            $payload['max_value'] = null;
        }

        $charge = LoanCharge::create($payload);

        return response()->json([
            'data' => new LoanChargeResource($charge->load(['incomeAccount:id,name,gl_code', 'receivableAccount:id,name,gl_code'])),
        ], 201);
    }

    public function show(LoanCharge $loanCharge)
    {
        return new LoanChargeResource($loanCharge->load(['incomeAccount:id,name,gl_code', 'receivableAccount:id,name,gl_code']));
    }

    public function update(UpdateLoanChargeRequest $request, LoanCharge $loanCharge)
    {
        if (! $this->supportsDefinitionsSchema()) {
            return response()->json([
                'message' => 'Loan charge definitions schema is not ready for this tenant. Run tenant migrations and retry.',
            ], 409);
        }

        $payload = $request->validated();

        if (array_key_exists('name', $payload) && ! array_key_exists('code', $payload)) {
            $payload['code'] = $this->generateCode($payload['name']);
        }

        if (($payload['max_value_type'] ?? $loanCharge->max_value_type) === 'none') {
            $payload['max_value'] = null;
        }

        $loanCharge->update($payload);

        return response()->json([
            'data' => new LoanChargeResource($loanCharge->fresh()->load(['incomeAccount:id,name,gl_code', 'receivableAccount:id,name,gl_code'])),
        ]);
    }

    public function destroy(LoanCharge $loanCharge)
    {
        if (Schema::connection('tenant')->hasTable('loan_product_charge') && $loanCharge->loanProducts()->exists()) {
            return response()->json([
                'message' => 'Cannot delete charge that is assigned to one or more loan products. Remove it from products first.',
            ], 422);
        }

        $appliedInLoans = false;
        if (Schema::connection('tenant')->hasTable('loan_applied_charges') && Schema::connection('tenant')->hasColumn('loan_applied_charges', 'loan_charge_id')) {
            $appliedInLoans = LoanAppliedCharge::query()->where('loan_charge_id', $loanCharge->id)->exists();
        }

        if ($appliedInLoans) {
            return response()->json([
                'message' => 'Cannot delete charge that has been applied to loans.',
            ], 422);
        }

        $loanCharge->delete();

        return response()->noContent();
    }

    public function toggle(LoanCharge $loanCharge)
    {
        if (! $this->hasColumn('is_active')) {
            return response()->json([
                'message' => 'is_active column is missing on this tenant schema. Run tenant migrations and retry.',
            ], 409);
        }

        $loanCharge->is_active = ! $loanCharge->is_active;
        $loanCharge->save();

        return response()->json([
            'data' => new LoanChargeResource($loanCharge->fresh()->load(['incomeAccount:id,name,gl_code', 'receivableAccount:id,name,gl_code'])),
        ]);
    }

    private function generateCode(string $name): string
    {
        $base = Str::upper(Str::slug($name, '-'));

        return Str::limit($base !== '' ? $base : 'LOAN-CHARGE', 24, '');
    }

    private function hasColumn(string $column): bool
    {
        return Schema::connection('tenant')->hasTable('loan_charges')
            && Schema::connection('tenant')->hasColumn('loan_charges', $column);
    }

    private function supportsDefinitionsSchema(): bool
    {
        return $this->hasColumn('name')
            && $this->hasColumn('category')
            && $this->hasColumn('charge_type')
            && $this->hasColumn('value');
    }
}
