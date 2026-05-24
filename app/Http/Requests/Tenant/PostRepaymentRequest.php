<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class PostRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'string', 'in:cash,bank_transfer,cheque,mobile_money'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'penalty_charges' => ['nullable', 'numeric', 'min:0'],
            'interest' => ['nullable', 'numeric', 'min:0'],
            'principal' => ['nullable', 'numeric', 'min:0'],
            'loan_officer_id' => ['nullable', 'integer'],
            'receipt_no' => ['nullable', 'string', 'max:100'],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Repayment amount must be greater than zero.',
            'payment_method.in' => 'Payment method must be one of: cash, bank transfer, cheque, or mobile money.',
            'payment_date.before_or_equal' => 'Payment date cannot be in the future.',
        ];
    }
}
