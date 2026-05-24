<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanPenaltyRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'loan_product_id' => $this->loan_product_id,
            'system_type' => $this->system_type,
            'penalty_type' => $this->penalty_type,
            'penalty_rate' => $this->penalty_rate,
            'grace_days' => $this->grace_days,
            'amount' => $this->amount,
            'amount_formatted' => TenantMoney::format($this->amount),
            'applies_to' => $this->applies_to,
            'branch_id' => $this->branch_id,
            'currency_code' => TenantMoney::code(),
        ];
    }
}
