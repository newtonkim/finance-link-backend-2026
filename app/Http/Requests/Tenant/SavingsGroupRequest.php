<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class SavingsGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'primary_contact_country_code' => ['required', 'string', 'max:5'],
            'primary_contact_phone' => ['required', 'string', 'max:20'],
            'other_contact_country_code' => ['required', 'string', 'max:5'],
            'other_contact_phone' => ['nullable', 'string', 'max:20'],
            'date_created' => ['required', 'date'],
            'location' => ['required', 'string'],
            'description' => ['required', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
        ];
    }
}
