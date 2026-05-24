<?php

namespace App\Tenant\Services;

use App\Http\Globals\GlobalHelpers;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;

class GeneralChargeUpdateOrCreateService extends GlobalHelpers
{
    protected function generalChargesUOrCFields(array $req): array
    {
        return $this->removeAllNullValues([
            'name' => $req['name'] ?? null,
            'amount' => $req['amount'] ?? null,
            'is_revenue' => $req['is_revenue'] ?? null,
            'application' => $req['application'] ?? null,
            'charge_type' => $req['charge_type'] ?? null,
            'where_to_apply' => $req['where_to_apply'] ?? null,
            'interval_type' => $req['interval_type'] ?? null,
            'interval' => $req['interval'] ?? null,
            'is_fine' => $req['is_fine'] ?? null,
            'credit_account_id' => $req['credit_account_id'] ?? null,
            'branch_id' => $req['branch_id'] ?? null,
        ]);
    }

    public function generalChargeCreate()
    {
        $req = request()->all();
        $fileds = $this->generalChargesUOrCFields($req);
        $fileds['is_fine'] = ($fileds['is_fine'] ?? 'no') === 'yes' ? true : false;
        $fileds['is_revenue'] = ($fileds['is_revenue'] ?? 'no') === 'yes' ? true : false;

        // Use Eloquent so we get the model back with its id, then sync the pivot.
        // The legacy UpdateOrCreateRecord helper returns a status, not the model,
        // which makes pivot wiring awkward.
        $isUpdate = ! empty($req['id']);
        if ($isUpdate) {
            $charge = GeneralCharge::on('tenant')->findOrFail($req['id']);
            $charge->fill($fileds)->save();
        } else {
            $charge = GeneralCharge::on('tenant')->create($fileds);
        }

        $savingProductIds = array_values(array_map('intval', $req['saving_product_ids'] ?? []));
        $triggerTypes = $this->resolveRegistrationTriggerTypes([
            'application' => $req['application'] ?? null,
            'saving_product_ids' => $savingProductIds,
            'trigger_types' => $req['trigger_types'] ?? [],
        ]);

        $this->syncSavingsPivot($charge, $savingProductIds, $triggerTypes);

        $List = app(GeneralChargeService::class);

        return $List->generalChargeList();
    }

    public function generalChargeDelete()
    {
        $this->DeleteRecord('general_charges', request());
        $List = app(GeneralChargeService::class);

        return $List->generalChargeList();
    }

    /**
     * Resolve the pivot trigger types from a validated/raw request payload.
     *
     * For application=on_registration with at least one saving_product_id we
     * force the trigger to ['registration'] regardless of what the client
     * sent — the client is not expected to set trigger_types for this path,
     * and the trigger_types validation rule (in:deposit,withdraw,transfer)
     * would reject 'registration' anyway. For all other paths the supplied
     * trigger_types pass through unchanged.
     */
    protected function resolveRegistrationTriggerTypes(array $payload): array
    {
        if (($payload['application'] ?? null) === 'on_registration' && ! empty($payload['saving_product_ids'] ?? [])) {
            return ['registration'];
        }

        return $payload['trigger_types'] ?? [];
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
     */
    protected function syncSavingsPivot(GeneralCharge $charge, array $productIds, array $triggerTypes): void
    {
        SavingsProductCharge::on('tenant')->where('general_charge_id', $charge->id)->delete();

        if (empty($productIds) || empty($triggerTypes)) {
            return;
        }

        $rows = [];
        $now = now();
        $chargeType = $charge->charge_type ?? 'amount';

        foreach ($productIds as $productId) {
            foreach ($triggerTypes as $triggerType) {
                $rows[] = [
                    'savings_product_id' => $productId,
                    'general_charge_id' => $charge->id,
                    'type' => $triggerType,
                    'name' => $charge->name,
                    'charge_type' => $chargeType,
                    'amount' => $charge->amount,
                    'minimum_amount' => 0,
                    // Default to reversible when the parent didn't set it
                    // (legacy generalChargeCreate path doesn't expose this
                    // field). Pivot column is NOT NULL.
                    'is_reversible' => $charge->is_reversible ?? true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        SavingsProductCharge::on('tenant')->insert($rows);
    }
}

function normalizeIds($value)
{
    if (empty($value)) {
        return null;
    }
    if (is_array($value)) {
        return array_map('intval', $value);
    }

    return array_map('intval', explode(',', $value));
}
