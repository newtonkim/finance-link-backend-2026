<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'reschedule_id' => $this->reschedule_id,
            'receipt_no' => $this->receipt_no,
            'transaction_ref' => $this->transaction_ref,
            'amount_paid' => $this->amount_paid,
            'amount_paid_formatted' => TenantMoney::format($this->amount_paid),
            'principal_portion' => $this->principal_portion,
            'principal_portion_formatted' => TenantMoney::format($this->principal_portion),
            'interest_portion' => $this->interest_portion,
            'interest_portion_formatted' => TenantMoney::format($this->interest_portion),
            'penalty_portion' => $this->penalty_portion,
            'penalty_portion_formatted' => TenantMoney::format($this->penalty_portion),
            'charges_portion' => $this->charges_portion,
            'charges_portion_formatted' => TenantMoney::format($this->charges_portion),
            'payment_date' => $this->payment_date?->format('Y-m-d'),
            'payment_method' => $this->payment_method,
            'reversal_flag' => $this->reversal_flag,
            'reversed_date' => $this->reversed_date?->format('Y-m-d H:i:s'),
            'collected_by' => $this->whenLoaded('collectedBy', fn () => $this->collectedBy ? [
                'id' => $this->collectedBy->id,
                'name' => $this->collectedBy->name,
            ] : null),
        ];
    }
}
