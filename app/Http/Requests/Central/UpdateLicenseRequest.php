<?php

namespace App\Http\Requests\Central;

use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLicenseRequest extends FormRequest
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
            'tenant_id' => ['sometimes', 'required', 'string', Rule::exists(Tenant::class, 'id')],
            'plan' => ['sometimes', 'required', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'expires_at' => ['sometimes', 'required', 'date', 'after:starts_at'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive,suspended,trial,expired'],
        ];
    }
}
