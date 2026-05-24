<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLoanSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'charge_deduction_mode' => 'nullable|string|in:deduct_from_principal,capitalize,debit_savings,pay_cash',
            'repayment_allocation_order' => 'nullable|string|in:principal_interest_penalties_charges,interest_principal_penalties_charges,penalties_charges_interest_principal,penalties_charges_principal_interest',
            'min_approvers' => 'nullable|integer|min:1',
            'max_approvers' => 'nullable|integer|min:1|gte:min_approvers',
            'allow_top_up' => 'nullable|boolean',
            'allow_reschedule' => 'nullable|boolean',
            'auto_penalty' => 'nullable|boolean',
            'penalty_grace_days' => 'nullable|integer|min:0',
            'loan_cycle_limit' => 'nullable|integer|min:1',
            'push_installments_on_holidays' => 'nullable|boolean',
            'push_installments_on_holidays_weekdays_only' => 'nullable|boolean',
            'relative_scheduling' => 'nullable|boolean',
            'topup_repayment_basis' => 'nullable|string|in:principal,principal_interest,outstanding_balance',
            'topup_min_percentage' => 'nullable|numeric|min:0|max:100',
            'topup_auto_disbursement' => 'nullable|boolean',

            // Reschedule fee settings
            'reschedule_fee_income_account_id' => 'nullable|integer',
            'reschedule_fee_enabled' => 'nullable|boolean',
            'reschedule_fee_type' => 'nullable|string|in:flat,percentage',
            'reschedule_fee_amount' => 'nullable|numeric|min:0',
            'reschedule_fee_basis' => 'nullable|string|in:outstanding_balance,new_principal,original_disbursed',
            'reschedule_fee_collection' => 'nullable|string|in:savings,capitalize,cash',

            'reschedule_product_change_fee_enabled' => 'nullable|boolean',
            'reschedule_product_change_fee_type' => 'nullable|string|in:flat,percentage',
            'reschedule_product_change_fee_amount' => 'nullable|numeric|min:0',
            'reschedule_product_change_fee_basis' => 'nullable|string|in:outstanding_balance,new_principal,original_disbursed',
            'reschedule_product_change_fee_collection' => 'nullable|string|in:savings,capitalize,cash',

            'reschedule_same_product_fee_enabled' => 'nullable|boolean',
            'reschedule_same_product_fee_type' => 'nullable|string|in:flat,percentage',
            'reschedule_same_product_fee_amount' => 'nullable|numeric|min:0',
            'reschedule_same_product_fee_basis' => 'nullable|string|in:outstanding_balance,new_principal,original_disbursed',
            'reschedule_same_product_fee_collection' => 'nullable|string|in:savings,capitalize,cash',

            'reschedule_other_charges_enabled' => 'nullable|boolean',
            'reschedule_other_charges_type' => 'nullable|string|in:flat,percentage',
            'reschedule_other_charges_amount' => 'nullable|numeric|min:0',
            'reschedule_other_charges_basis' => 'nullable|string|in:outstanding_balance,new_principal,original_disbursed',
            'reschedule_other_charges_collection' => 'nullable|string|in:savings,capitalize,cash',
        ];
    }
}
