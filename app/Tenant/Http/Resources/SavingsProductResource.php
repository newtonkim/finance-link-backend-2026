<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'minimum_balance' => $this->minimum_balance,
            'minimum_maturity_months' => $this->minimum_maturity_months,
            'dormancy_period_months' => $this->dormancy_period_months,
            'charge_on_deposit' => $this->charge_on_deposit,
            'charge_on_withdraw' => $this->charge_on_withdraw,
            'charge_on_transfer' => $this->charge_on_transfer,
            'monthly_fee_enabled' => $this->monthly_fee_enabled,
            'monthly_fee_type' => $this->monthly_fee_type,
            'monthly_fee_amount' => $this->monthly_fee_amount,
            'monthly_fee_deduction_day' => $this->monthly_fee_deduction_day,
            'loyalty_fee_enabled' => $this->loyalty_fee_enabled,
            'loyalty_adjustment_type' => $this->loyalty_adjustment_type,
            'loyalty_adjustment_value' => $this->loyalty_adjustment_value,
            'interest_rate' => $this->interest_rate,
            'interest_payout_type' => $this->interest_payout_type,
            'interest_posting_frequency' => $this->interest_posting_frequency,
            'default_tenor_months' => $this->default_tenor_months,
            'maturity_action' => $this->maturity_action,
            'convert_to_product_id' => $this->convert_to_product_id,
            'interest_expense_account_id' => $this->interest_expense_account_id,
            'interest_payable_account_id' => $this->interest_payable_account_id,
            'charges' => $this->whenLoaded('charges'),
        ];
    }
}
