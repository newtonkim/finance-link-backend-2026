<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'loan_no' => $this->loan_no,
            'status' => $this->status,
            'is_rescheduled' => (bool) $this->is_rescheduled,
            'principal' => $this->principal,
            'principal_formatted' => TenantMoney::format($this->principal),
            'outstanding_balance' => $this->outstanding_balance,
            'outstanding_balance_formatted' => TenantMoney::format($this->outstanding_balance),
            'interest_rate' => $this->interest_rate,
            'term_months' => $this->term_months,
            'disbursed_at' => $this->disbursed_at?->format('Y-m-d'),
            'approved_at' => $this->loanApplication?->approved_at?->format('Y-m-d'),
            'disbursement_method' => $this->disbursement_method,
            'member' => $this->whenLoaded('member', fn () => [
                'id' => $this->member->id,
                'name' => $this->member->name,
                'member_number' => $this->member->member_number ?? $this->member->code ?? null,
            ]),
            'loan_product' => $this->whenLoaded('loanProduct', fn () => [
                'id' => $this->loanProduct->id,
                'name' => $this->loanProduct->name,
                'code' => $this->loanProduct->code,
                'interest_method' => $this->loanProduct->interest_method,
            ]),
            'next_due_date' => $this->whenLoaded('nextSchedule', fn () => $this->nextSchedule?->due_date?->format('Y-m-d')),
            'next_installment_amount' => $this->whenLoaded('nextSchedule', fn () => $this->nextSchedule?->total_due),
            'reschedule_date' => $this->whenLoaded('latestReschedule', fn () => $this->latestReschedule?->reschedule_date?->format('Y-m-d')),
        ];
    }
}
