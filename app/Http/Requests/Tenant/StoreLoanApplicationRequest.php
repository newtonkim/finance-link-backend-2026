<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanApplicationRequest extends FormRequest
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
            'requested_amount' => ['required', 'numeric', 'min:0.01'],
            'requested_term' => ['required', 'integer', 'min:1'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'repayment_source' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
        ];
    }
}
