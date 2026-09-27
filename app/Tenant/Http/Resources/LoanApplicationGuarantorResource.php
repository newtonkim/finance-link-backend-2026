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
            'code' => $this->code,
            'loan_application_id' => $this->loan_application_id,
            'guarantor_type' => $this->guarantor_type,
            'guarantor_id' => $this->guarantor_id,
            'guarantor_account_id' => $this->guarantor_account_id,
            'name' => $this->guarantorName(),
            'guarantor_code' => $this->guarantor_type === 'group' ? $this->group?->code : $this->member?->code,
            'guarantee_amount' => (float) $this->guarantee_amount,
            'guarantee_amount_formatted' => TenantMoney::format($this->guarantee_amount),
            'status' => $this->status,
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
