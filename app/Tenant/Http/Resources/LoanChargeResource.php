<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanChargeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'category' => $this->category,
            'charge_type' => $this->charge_type,
            'value' => $this->value,
            'frequency' => $this->frequency,
            'grace_days' => (int) ($this->grace_days ?? 0),
            'max_value' => $this->max_value,
            'max_value_type' => $this->max_value_type,
            'is_active' => (bool) $this->is_active,
            'income_account_id' => $this->income_account_id,
            'receivable_account_id' => $this->receivable_account_id,
            'income_account' => $this->whenLoaded('incomeAccount', fn () => $this->incomeAccount ? [
                'id' => $this->incomeAccount->id,
                'name' => $this->incomeAccount->name,
                'gl_code' => $this->incomeAccount->gl_code,
            ] : null),
            'receivable_account' => $this->whenLoaded('receivableAccount', fn () => $this->receivableAccount ? [
                'id' => $this->receivableAccount->id,
                'name' => $this->receivableAccount->name,
                'gl_code' => $this->receivableAccount->gl_code,
            ] : null),
            'description' => $this->description,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
