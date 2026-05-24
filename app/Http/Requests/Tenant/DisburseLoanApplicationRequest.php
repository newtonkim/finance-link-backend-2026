<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class DisburseLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'disbursement_method' => ['required', 'string', 'in:savings_account,mobile_money,bank_transfer,cheque,cash'],
            'disbursement_reference' => ['nullable', 'string', 'max:100'],
            'disbursement_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],

            // Channel-specific fields
            'savings_account_id' => ['required_if:disbursement_method,savings_account', 'nullable', 'integer', 'exists:tenant.savings_accounts,id'],
            'mobile_money_provider' => ['required_if:disbursement_method,mobile_money', 'nullable', 'string', 'in:mtn,airtel'],
            'mobile_money_number' => ['required_if:disbursement_method,mobile_money', 'nullable', 'string', 'max:20'],

            // Charge deduction mode (overrides branch loan_settings if provided)
            'charge_deduction_mode' => ['nullable', 'string', 'in:deduct_from_principal,debit_savings,pay_cash,capitalize'],
        ];
    }

    public function messages(): array
    {
        return [
            'disbursement_method.in' => 'Disbursement method must be one of: savings account, mobile money, bank transfer, cheque, or cash.',
            'disbursement_date.before_or_equal' => 'Disbursement date cannot be in the future.',
            'savings_account_id.required_if' => 'A savings account is required when disbursing to a savings account.',
            'savings_account_id.exists' => 'The selected savings account does not exist.',
            'mobile_money_provider.required_if' => 'Mobile money provider is required when disbursing via mobile money.',
            'mobile_money_number.required_if' => 'Mobile money number is required when disbursing via mobile money.',
        ];
    }
}
