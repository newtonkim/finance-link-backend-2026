<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(['processing_fee', 'penalty', 'late_fee', 'appraisal_fee', 'disbursement_fee', 'other'])],
            'charge_type' => ['required', Rule::in(['flat', 'percentage'])],
            'value' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', Rule::in(['one_time', 'daily', 'weekly', 'monthly'])],
            'grace_days' => ['nullable', 'integer', 'min:0'],
            'max_value_type' => ['nullable', Rule::in(['none', 'flat_cap', 'percentage_of_outstanding'])],
            'max_value' => ['nullable', 'numeric', 'min:0', 'required_if:max_value_type,flat_cap,percentage_of_outstanding'],
            'is_active' => ['nullable', 'boolean'],
            'income_account_id' => [
                'nullable',
                'integer',
                Rule::exists('tenant.chart_of_accounts', 'id')->where(fn ($q) => $q->where('is_postable', true)),
            ],
            'receivable_account_id' => [
                'nullable',
                'integer',
                Rule::exists('tenant.chart_of_accounts', 'id')->where(fn ($q) => $q->where('is_postable', true)),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (($this->input('charge_type') === 'percentage') && (float) $this->input('value', 0) > 100) {
                $validator->errors()->add('value', 'The value must not be greater than 100.');
            }

            if (($this->input('max_value_type') === 'percentage_of_outstanding') && (float) $this->input('max_value', 0) > 100) {
                $validator->errors()->add('max_value', 'Cap percentage cannot exceed 100%.');
            }
        });
    }
}
