<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class AppraiseLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'recommended_amount' => ['required', 'numeric', 'min:1'],
            'recommended_term' => ['required', 'integer', 'min:1'],
            'recommended_interest_rate' => ['required', 'numeric', 'min:0'],
            'risk_rating' => ['required', 'in:low,medium,high,critical'],
            'appraisal_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
