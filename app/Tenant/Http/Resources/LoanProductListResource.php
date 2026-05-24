<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;

class LoanProductListResource extends LoanProductBaseResource
{
    public function toArray(Request $request): array
    {
        $isInUse = $this->resolveIsInUse();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'interest_method' => $this->interest_method,
            'repayment_structure' => $this->repayment_structure,
            'interest_rate' => $this->interest_rate,
            'interest_period' => $this->interest_period,
            'loan_duration' => $this->loan_duration,
            'duration_type' => $this->duration_type,
            'repayment_cycle' => $this->repayment_cycle,
            'grace_period' => $this->grace_period ?? 0,
            'penalty_grace_days' => $this->penalty_grace_days ?? 0,
            'is_active' => $this->is_active,
            'requires_approval' => $this->requires_approval,
            'loan_count' => $this->loans_count ?? 0,
            'is_in_use' => $isInUse,
            'can_edit_core_fields' => $this->resolveCanEditCoreFields($isInUse),
            'charge_ids' => $this->whenLoaded('charges', fn () => $this->charges->pluck('id')->values()->all(), []),
            'charges' => $this->whenLoaded('charges', fn () => $this->charges->map(fn ($charge) => [
                'id' => $charge->id,
                'name' => $charge->name,
                'category' => $charge->category,
                'charge_type' => $charge->charge_type,
                'value' => $charge->value,
                'frequency' => $charge->frequency,
                'grace_days' => $charge->grace_days,
            ])->values()->all(), []),
        ];
    }
}
