<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class OnboardingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shares_compulsory' => ['sometimes', 'boolean'],
            'min_shares_on_onboarding' => ['sometimes', 'integer', 'min:0'],
            'share_price' => ['sometimes', 'numeric', 'min:0'],
            'share_payment_account_id' => ['sometimes', 'nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'shares_compulsory_applies_to_existing' => ['sometimes', 'boolean'],
            'auto_create_savings_account' => ['sometimes', 'boolean'],
            'require_member_approval' => ['sometimes', 'boolean'],
            'loyal_member_min_tenure_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'hide_initial_deposit_field' => ['sometimes', 'boolean'],
            'hide_opening_balance_field' => ['sometimes', 'boolean'],
            'hide_is_shareholder_field' => ['sometimes', 'boolean'],
            'reversal_requires_approval' => ['sometimes', 'boolean'],
            'reversal_approver_roles' => ['sometimes', 'array'],
            'reversal_approver_roles.*' => ['string'],
            'reversal_max_days' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
