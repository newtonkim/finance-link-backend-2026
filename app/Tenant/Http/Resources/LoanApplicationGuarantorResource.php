<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanApplicationGuarantorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'member' => $this->whenLoaded('member', fn () => [
                'id' => $this->member->id,
                'name' => $this->member->name,
                'member_no' => $this->member->code,
                'savings_balance' => (float) $this->member->savingsAccounts()->sum('balance'),
                'savings_balance_formatted' => TenantMoney::format(
                    $this->member->savingsAccounts()->sum('balance')
                ),
            ]),
            'guarantee_amount' => $this->guarantee_amount,
            'guarantee_amount_formatted' => TenantMoney::format($this->guarantee_amount),
            'created_at' => $this->created_at,
        ];
    }
}
