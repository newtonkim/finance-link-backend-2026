<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('tenant.loan_products', 'name')],
            'code' => ['nullable', 'string', 'max:100', Rule::unique('tenant.loan_products', 'code')],
            'description' => ['nullable', 'string'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'gte:min_amount'],
            'interest_rate' => ['nullable', 'numeric', 'min:0'],
            'interest_method' => ['nullable', Rule::in(['flat', 'reducing_balance'])],
            'repayment_structure' => ['nullable', Rule::in(['equal_installment', 'equal_principal', 'interest_only_balloon'])],
            'interest_period' => ['nullable', Rule::in(['daily', 'weekly', 'monthly', 'yearly', 'per_month', 'per_year'])],
            'loan_duration' => ['nullable', 'integer', 'min:1'],
            'duration_type' => ['nullable', Rule::in(['days', 'weeks', 'months', 'years'])],
            'repayment_cycle' => ['nullable', Rule::in(['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'annually', 'yearly'])],
            'grace_period' => ['nullable', 'integer', 'min:0'],
            'penalty_grace_days' => ['nullable', 'integer', 'min:0'],
            'savings_appraisal_threshold' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'warning_days' => ['nullable', 'integer', 'min:0'],
            'max_securities' => ['nullable', 'integer', 'min:1'],
            'security_value_percentage' => ['nullable', 'numeric', 'min:0'],
            'allow_sub_schedule' => ['nullable', 'boolean'],
            'penalty_rate' => ['nullable', 'numeric', 'min:0'],
            'penalty_type' => ['nullable', Rule::in(['none', 'flat', 'percentage'])],
            'requires_approval' => ['nullable', 'boolean'],
            'allow_top_up' => ['nullable', 'boolean'],
            'allow_reschedule' => ['nullable', 'boolean'],
            'processing_fee_type' => ['nullable', Rule::in(['none', 'flat', 'percentage'])],
            'processing_fee_value' => ['nullable', 'numeric', 'min:0'],
            'loan_portfolio_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'interest_income_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'interest_receivable_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'penalty_income_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'penalty_receivable_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'disbursement_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'charges_income_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'charges_receivable_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'approval_setting' => ['nullable', 'array'],
            'approval_setting.enabled' => ['nullable', 'boolean'],
            'approval_setting.quorum_size' => ['nullable', 'integer', 'min:1'],
            'approval_setting.approval_threshold' => ['nullable', 'integer', 'min:1'],
            'required_documents' => ['nullable', 'array'],
            'charge_ids' => ['nullable', 'array'],
            'charge_ids.*' => [
                'integer',
                Rule::exists('tenant.loan_charges', 'id')->where(fn ($q) => $q->where('is_active', true)),
            ],
            'required_documents.*.document_type_id' => ['required_with:required_documents', 'integer', 'exists:tenant.document_types,id'],
            'required_documents.*.required_stage' => ['nullable', Rule::in(['draft', 'submission', 'review', 'approval', 'disbursement'])],
            'required_documents.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'required_documents.*.is_required' => ['nullable', 'boolean'],
            'required_documents.*.is_active' => ['nullable', 'boolean'],
            'required_documents.*.notes' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
            'penalty_rules' => ['nullable', 'array'],
            'penalty_rules.*.penalty_type' => ['required_with:penalty_rules', 'string', 'max:50'],
            'penalty_rules.*.penalty_rate' => ['nullable', 'numeric', 'min:0'],
            'penalty_rules.*.grace_days' => ['nullable', 'integer', 'min:0'],
            'penalty_rules.*.amount' => ['nullable', 'numeric', 'min:0'],
            'penalty_rules.*.applies_to' => ['nullable', 'string', 'max:100'],
        ];
    }
}
