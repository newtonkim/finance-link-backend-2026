<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;

class LoanProductResource extends LoanProductBaseResource
{
    public function toArray(Request $request): array
    {
        $isInUse = $this->resolveIsInUse();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'min_amount' => $this->min_amount,
            'min_amount_formatted' => TenantMoney::format($this->min_amount),
            'max_amount' => $this->max_amount,
            'max_amount_formatted' => TenantMoney::format($this->max_amount),
            'interest_rate' => $this->interest_rate,
            'interest_method' => $this->interest_method,
            'repayment_structure' => $this->repayment_structure,
            'interest_period' => $this->interest_period,
            'loan_duration' => $this->loan_duration,
            'duration_type' => $this->duration_type,
            'repayment_cycle' => $this->repayment_cycle,
            'required_documents' => $this->resolveRequiredDocuments(),
            'grace_period' => $this->grace_period,
            'penalty_grace_days' => $this->penalty_grace_days ?? 0,
            'savings_appraisal_threshold' => $this->savings_appraisal_threshold,
            'warning_days' => $this->warning_days,
            'max_securities' => $this->max_securities,
            'security_value_percentage' => $this->security_value_percentage,
            'allow_sub_schedule' => $this->allow_sub_schedule,
            'penalty_rate' => $this->penalty_rate,
            'penalty_type' => $this->penalty_type,
            'min_guarantors' => $this->min_guarantors ?? 0,
            'max_guarantors' => $this->max_guarantors ?? 0,
            'requires_approval' => $this->requires_approval,
            'allow_top_up' => $this->allow_top_up,
            'allow_reschedule' => $this->allow_reschedule,
            'processing_fee_type' => $this->processing_fee_type,
            'processing_fee_value' => $this->processing_fee_value,
            'processing_fee_value_formatted' => $this->processing_fee_type === 'flat'
                ? TenantMoney::format($this->processing_fee_value)
                : null,
            'loan_portfolio_account_id' => $this->loan_portfolio_account_id,
            'interest_income_account_id' => $this->interest_income_account_id,
            'interest_receivable_account_id' => $this->interest_receivable_account_id,
            'penalty_income_account_id' => $this->penalty_income_account_id,
            'penalty_receivable_account_id' => $this->penalty_receivable_account_id,
            'disbursement_account_id' => $this->disbursement_account_id,
            'charges_income_account_id' => $this->charges_income_account_id,
            'charges_receivable_account_id' => $this->charges_receivable_account_id,
            'loan_count' => $this->loans_count ?? 0,
            'is_in_use' => $isInUse,
            'can_edit_core_fields' => $this->resolveCanEditCoreFields($isInUse),
            'is_active' => $this->is_active,
            'currency_code' => TenantMoney::code(),
            'portfolio_account' => $this->whenLoaded('portfolioAccount', fn () => $this->accountSummary($this->portfolioAccount)),
            'interest_income_account' => $this->whenLoaded('interestIncomeAccount', fn () => $this->accountSummary($this->interestIncomeAccount)),
            'interest_receivable_account' => $this->whenLoaded('interestReceivableAccount', fn () => $this->accountSummary($this->interestReceivableAccount)),
            'penalty_income_account' => $this->whenLoaded('penaltyIncomeAccount', fn () => $this->accountSummary($this->penaltyIncomeAccount)),
            'penalty_receivable_account' => $this->whenLoaded('penaltyReceivableAccount', fn () => $this->accountSummary($this->penaltyReceivableAccount)),
            'disbursement_account' => $this->whenLoaded('disbursementAccount', fn () => $this->accountSummary($this->disbursementAccount)),
            'charges_income_account' => $this->whenLoaded('chargesIncomeAccount', fn () => $this->accountSummary($this->chargesIncomeAccount)),
            'charges_receivable_account' => $this->whenLoaded('chargesReceivableAccount', fn () => $this->accountSummary($this->chargesReceivableAccount)),
            'approval_setting' => $this->whenLoaded('approvalSetting', fn () => $this->approvalSetting ? [
                'enabled' => true,
                'quorum_size' => $this->approvalSetting->quorum_size,
                'approval_threshold' => $this->approvalSetting->approval_threshold,
            ] : null),
            'penalty_rules' => LoanPenaltyRuleResource::collection($this->whenLoaded('penaltyRules')),
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

    private function accountSummary($account): ?array
    {
        if (! $account) {
            return null;
        }

        return [
            'id' => $account->id,
            'name' => $account->name,
            'gl_code' => $account->gl_code,
        ];
    }

    private function resolveRequiredDocuments(): array
    {
        return $this->requiredDocuments
            ->filter(fn ($row) => $row->documentType)
            ->map(fn ($row) => [
                'id' => $row->id,
                'document_type_id' => $row->document_type_id,
                'document_type_code' => $row->documentType->code,
                'document_type_name' => $row->documentType->name,
                'required_stage' => $row->required_stage,
                'sort_order' => $row->sort_order,
                'is_required' => $row->is_required,
                'is_active' => $row->is_active,
                'notes' => $row->notes,
            ])
            ->values()
            ->all();
    }
}
