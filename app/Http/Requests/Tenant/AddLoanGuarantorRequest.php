<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape checks only. Whether this guarantor may stand for this loan (membership,
 * self-guarantee, capacity, maximum count) is decided by LoanGuarantorService,
 * which every path that saves a guarantor goes through.
 */
class AddLoanGuarantorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guarantor_type' => ['required', 'string', 'in:individual,group'],
            'guarantor_id' => ['required', 'integer', 'min:1'],
            'guarantor_account_id' => ['nullable', 'integer', 'min:1'],
            'guarantee_amount' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
