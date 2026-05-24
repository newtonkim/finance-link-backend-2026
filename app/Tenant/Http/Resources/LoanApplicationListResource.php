<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanApplicationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $daysPending = null;

        if ($this->submitted_at) {
            $daysPending = (int) now()->diffInDays($this->submitted_at);
        }

        return [
            'id' => $this->id,
            'application_no' => $this->application_no,
            'member' => $this->whenLoaded('member', fn () => [
                'id' => $this->member->id,
                'name' => $this->member->name,
                'member_no' => $this->member->code,
            ]),
            'loan_product' => $this->whenLoaded('loanProduct', fn () => [
                'id' => $this->loanProduct->id,
                'name' => $this->loanProduct->name,
                'code' => $this->loanProduct->code,
                'max_amount' => $this->loanProduct->max_amount,
                'max_amount_formatted' => TenantMoney::format($this->loanProduct->max_amount),
                'processing_fee_type' => $this->loanProduct->processing_fee_type,
                'processing_fee_value' => $this->loanProduct->processing_fee_value,
                'disbursement_account_name' => $this->loanProduct->relationLoaded('disbursementAccount')
                    ? ($this->loanProduct->disbursementAccount?->name)
                    : null,
                'portfolio_account_name' => $this->loanProduct->relationLoaded('portfolioAccount')
                    ? ($this->loanProduct->portfolioAccount?->name)
                    : null,
                'fee_income_account_name' => $this->loanProduct->relationLoaded('interestIncomeAccount')
                    ? ($this->loanProduct->interestIncomeAccount?->name)
                    : null,
            ]),
            'requested_amount' => $this->requested_amount,
            'requested_amount_formatted' => TenantMoney::format($this->requested_amount),
            'requested_term' => $this->requested_term,
            'recommended_amount' => $this->recommended_amount,
            'recommended_amount_formatted' => TenantMoney::format($this->recommended_amount),
            'recommended_term' => $this->recommended_term,
            'approved_amount' => $this->approved_amount,
            'approved_amount_formatted' => TenantMoney::format($this->approved_amount),
            'approved_term' => $this->approved_term,
            'approved_at' => $this->approved_at,
            'risk_rating' => $this->risk_rating,
            'approvals_count' => $this->whenLoaded('approvals', fn () => $this->approvals->count(), 0),
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'submitted_at' => $this->submitted_at,
            'created_at' => $this->created_at,
            'days_pending' => $daysPending,
            'currency_code' => TenantMoney::code(),
        ];
    }
}
