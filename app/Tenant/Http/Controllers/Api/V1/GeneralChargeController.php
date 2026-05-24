<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;
use App\Tenant\Services\GeneralChargeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GeneralChargeController extends GeneralChargeService
{
    private const TRIGGER_TYPES = ['deposit', 'withdraw', 'transfer'];

    public function get_general_charge_details()
    {
        return $this->Response(['data' => self::generalChargeDetail()]);
    }

    public function get_general_charge_list()
    {
        return $this->Response(['data' => self::generalChargeList()]);
    }

    public function create_general_charge()
    {
        return $this->Response(['data' => self::generalChargeCreate()]);
    }

    public function get_general_charge_delete()
    {
        return $this->Response(['data' => self::generalChargeDelete()]);
    }

    public function index()
    {
        $charges = GeneralCharge::with(['creditAccount', 'productCharges'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($charge) {
                $data = $charge->toArray();
                $data['credit_account_name'] = $charge->creditAccount
                    ? $charge->creditAccount->gl_code.' - '.$charge->creditAccount->name
                    : null;
                $data['saving_product_ids'] = $charge->productCharges->pluck('savings_product_id')->unique()->values()->all();
                $data['trigger_types'] = $charge->productCharges->pluck('type')->unique()->values()->all();

                return $data;
            });

        return response()->json([
            'data' => $charges,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateChargePayload($request);



        $charge = DB::connection('tenant')->transaction(function () use ($validated) {
            $charge = GeneralCharge::create([
                'name' => $validated['name'],
                'is_revenue' => $validated['is_revenue'] === 'yes',
                'application' => $validated['application'],
                'where_to_apply' => $validated['where_to_apply'] ?? null,
                'is_fine' => ($validated['is_fine'] ?? 'no') === 'yes',
                'charge_type' => $validated['charge_type'] ?? null,
                'amount' => $validated['amount'],
                'interval_type' => $validated['interval_type'] ?? null,
                'interval' => $validated['interval'] ?? null,
                'credit_account_id' => $validated['credit_account_id'] ?? null,
                'is_reversible' => $validated['is_reversible'] ?? true,
            ]);

            $this->syncSavingsPivot(
                $charge,
                $validated['saving_product_ids'] ?? [],
                $this->resolveRegistrationTriggerTypes($validated),
            );

            return $charge->fresh('productCharges');
        });

        return response()->json([
            'message' => 'Charge created successfully.',
            'data' => $charge,
        ], 201);
    }

    public function update(Request $request, GeneralCharge $generalCharge)
    {
        $validated = $this->validateChargePayload($request);

        $generalCharge = DB::connection('tenant')->transaction(function () use ($validated, $generalCharge) {
            $generalCharge->update([
                'name' => $validated['name'],
                'is_revenue' => $validated['is_revenue'] === 'yes',
                'application' => $validated['application'],
                'where_to_apply' => $validated['where_to_apply'] ?? null,
                'is_fine' => ($validated['is_fine'] ?? 'no') === 'yes',
                'charge_type' => $validated['charge_type'] ?? null,
                'amount' => $validated['amount'],
                'interval_type' => $validated['interval_type'] ?? null,
                'interval' => $validated['interval'] ?? null,
                'credit_account_id' => $validated['credit_account_id'] ?? null,
                'is_reversible' => $validated['is_reversible'] ?? $generalCharge->is_reversible,
            ]);

            $this->syncSavingsPivot(
                $generalCharge,
                $validated['saving_product_ids'] ?? [],
                $this->resolveRegistrationTriggerTypes($validated),
            );

            return $generalCharge->fresh('productCharges');
        });

        return response()->json([
            'message' => 'Charge updated successfully.',
            'data' => $generalCharge,
        ]);
    }

    public function toggle(GeneralCharge $generalCharge)
    {
        $generalCharge->update(['is_active' => ! $generalCharge->is_active]);

        return response()->json([
            'message' => 'Charge '.($generalCharge->is_active ? 'activated' : 'deactivated').' successfully.',
            'data' => $generalCharge,
        ]);
    }

    public function toggleReversible(GeneralCharge $generalCharge)
    {
        $generalCharge->update(['is_reversible' => ! $generalCharge->is_reversible]);

        return response()->json([
            'message' => 'Charge marked as '.($generalCharge->is_reversible ? 'reversible' : 'non-reversible').'.',
            'data' => $generalCharge,
        ]);
    }

    public function destroy(GeneralCharge $generalCharge)
    {
        DB::connection('tenant')->transaction(function () use ($generalCharge) {
            SavingsProductCharge::on('tenant')->where('general_charge_id', $generalCharge->id)->delete();
            $generalCharge->delete();
        });

        return response()->json(['message' => 'Charge deleted successfully.']);
    }

    /**
     * Shared validation for store + update.
     *
     * `saving_product_ids` and `trigger_types` are conditionally required: a
     * savings-event charge (application=other AND where_to_apply=savings) only
     * makes sense if both are populated, otherwise the user saves a no-op
     * charge that never fires. This guard exists because the exact silent
     * misfire it prevents is what scope-B of Gap 4 was opened to eliminate.
     */
    private function validateChargePayload(Request $request): array
    {
        $isSavingsEvent = $request->input('application') === 'other'
            && $request->input('where_to_apply') === 'savings';

        $savingsListRule = Rule::requiredIf(fn () => $isSavingsEvent);

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_revenue' => ['required', 'in:yes,no'],
            'application' => ['required', 'in:on_shares,on_registration,other'],
            'where_to_apply' => ['nullable', 'in:savings,shares'],
            'is_fine' => ['nullable', 'in:yes,no'],
            'charge_type' => ['nullable', 'in:percentage,amount'],
            'amount' => ['required', 'numeric', 'min:0'],
            'interval_type' => ['nullable', 'in:days,weeks,months,years'],
            'interval' => ['nullable', 'integer', 'min:1'],
            'credit_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'saving_product_ids' => [$savingsListRule, 'array', 'min:'.($isSavingsEvent ? 1 : 0)],
            'saving_product_ids.*' => ['integer', 'exists:tenant.savings_products,id'],
            'trigger_types' => [$savingsListRule, 'array', 'min:'.($isSavingsEvent ? 1 : 0)],
            'trigger_types.*' => ['string', 'in:'.implode(',', self::TRIGGER_TYPES)],
            'is_reversible' => ['boolean'],
        ]);
    }

    /**
     * Replace this charge's savings_product_charges pivot rows with the
     * cross-product of the supplied products × trigger types. Existing rows
     * pointing at this charge are deleted first so a save is idempotent.
     *
     * The pivot's denormalised columns (name, amount, charge_type, is_reversible)
     * are copied from the parent charge so any legacy reader of the pivot still
     * sees consistent values, but at application time only the FK is followed
     * (ChargeCalculatorService reads $productCharge->generalCharge directly).
     *
     * Both methods are inherited from GeneralChargeUpdateOrCreateService as
     * protected — defined there so the legacy generalChargeCreate() path can
     * write pivot rows too. Don't redeclare them here.
     */
}
