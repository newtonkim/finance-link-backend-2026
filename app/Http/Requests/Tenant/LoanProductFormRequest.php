<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoanProductFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('loanProduct')?->id;

        return [
            // Core product fields
            'name' => ['required', 'string', 'max:255', Rule::unique('tenant.loan_products', 'name')->ignore($productId)->whereNull('deleted_at')],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'interest_rate' => ['nullable', 'numeric', 'min:0'],
            'interest_method' => ['nullable', Rule::in(['flat', 'reducing_balance'])],
            'interest_period' => ['nullable', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'loan_duration' => ['nullable', 'integer', 'min:1'],
            'duration_type' => ['nullable', Rule::in(['days', 'weeks', 'months', 'years'])],
            'repayment_cycle' => ['nullable', Rule::in(['daily', 'weekly', 'biweekly', 'monthly'])],
            'min_guarantors' => ['nullable', 'integer', 'min:0'],
            'max_guarantors' => ['nullable', 'integer', 'min:0'],
            'grace_period' => ['nullable', 'integer', 'min:0'],
            'penalty_rate' => ['nullable', 'numeric', 'min:0'],
            'penalty_type' => ['nullable', 'string', 'max:100'],
            'charges_income_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'charges_receivable_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'is_active' => ['boolean'],
            'required_documents' => ['nullable', 'array'],
            'required_documents.*.document_type_id' => ['required_with:required_documents', 'integer', 'exists:tenant.document_types,id'],
            'required_documents.*.required_stage' => ['nullable', Rule::in(['draft', 'submission', 'review', 'approval', 'disbursement'])],
            'required_documents.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'required_documents.*.is_required' => ['nullable', 'boolean'],
            'required_documents.*.is_active' => ['nullable', 'boolean'],
            'required_documents.*.notes' => ['nullable', 'string'],

            // Nested penalty rules
            'penalty_rules' => ['nullable', 'array'],
            'penalty_rules.*.penalty_type' => ['nullable', 'string', 'max:100'],
            'penalty_rules.*.penalty_rate' => ['nullable', 'numeric', 'min:0'],
            'penalty_rules.*.grace_days' => ['nullable', 'integer', 'min:0'],
            'penalty_rules.*.amount' => ['nullable', 'numeric', 'min:0'],
            'penalty_rules.*.applies_to' => ['nullable', 'string', 'max:100'],
            'penalty_rules.*.branch_id' => ['nullable', 'integer'],
        ];
    }
}
