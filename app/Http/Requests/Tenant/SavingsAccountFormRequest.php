<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class SavingsAccountFormRequest extends FormRequest
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
     */
    public function rules(): array
    {
        return [
            'member_id' => ['required', 'exists:tenant.members,id'],
            'savings_product_id' => ['required', 'exists:tenant.savings_products,id'],
            'account_type' => ['required', 'string'],
            'is_new_account' => ['required', 'boolean'],
            'initial_deposit' => ['required', 'numeric', 'min:0'],
            'consider_min_balance' => ['required', 'boolean'],
            'status' => ['nullable', 'in:active,dormant,closed'],
            'credited_account_id' => ['nullable', 'integer'], // Conceptual for fund source
            'charges' => ['nullable', 'array'],
        ];
    }
}
