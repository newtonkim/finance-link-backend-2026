<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class EligibilityCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => ['required', 'integer', 'exists:tenant.members,id'],
            'loan_product_id' => ['required', 'integer', 'exists:tenant.loan_products,id'],
            'requested_amount' => ['required', 'numeric', 'min:1'],
            'requested_term' => ['required', 'integer', 'min:1'],
        ];
    }
}
