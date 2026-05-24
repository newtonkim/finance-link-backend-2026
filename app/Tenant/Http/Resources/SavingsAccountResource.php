<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavingsAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $minimumBalance = $this->consider_min_balance
            ? (float) ($this->savingsProduct?->minimum_balance ?? 0)
            : 0;

        return [
            'id' => $this->id,
            'account_no' => $this->account_no,
            'account_type' => $this->account_type,
            'balance' => $this->balance,
            'balance_formatted' => TenantMoney::format($this->balance),
            'initial_deposit' => $this->initial_deposit,
            'initial_deposit_formatted' => TenantMoney::format($this->initial_deposit),
            'status' => $this->status,
            'consider_min_balance' => (bool) $this->consider_min_balance,
            'minimum_balance' => $minimumBalance,
            'minimum_balance_formatted' => TenantMoney::format($minimumBalance),
            'withdrawable_amount' => max((float) $this->balance - $minimumBalance, 0),
            'withdrawable_amount_formatted' => TenantMoney::format(max((float) $this->balance - $minimumBalance, 0)),
            'currency_code' => TenantMoney::code(),
                        'payment_mod' => $account->payment_mod_account_id??null,

            'member' => $this->whenLoaded('member', fn () => [
                'id' => $this->member->id,
                'name' => $this->member->name,
                'member_number' => $this->member->member_number,
            ]),
            'savings_product' => $this->whenLoaded('savingsProduct', fn () => [
                'id' => $this->savingsProduct->id,
                'name' => $this->savingsProduct->name,
            ]),
            'opening_balance' => $this->opening_balance,
            'interest_rate' => $this->interest_rate,
            'tenor_months' => $this->tenor_months,
            'maturity_date' => $this->maturity_date,
            'next_interest_date' => $this->next_interest_date,
            'maturity_action' => $this->maturity_action,
            'payout_savings_account_id' => $this->payout_savings_account_id,
            'last_interest_posted_at' => $this->last_interest_posted_at,
            'created_at' => $this->created_at?->toDateString(),
        ];
    }
}
