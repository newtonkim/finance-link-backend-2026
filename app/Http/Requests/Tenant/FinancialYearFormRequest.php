<?php

namespace App\Http\Requests\Tenant;

use App\Tenant\Modules\Settings\Models\FinancialYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FinancialYearFormRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ];
    }

    /**
     * Configure the validator instance — check for duplicate date range.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $query = FinancialYear::where('start_date', $this->start_date)
                ->where('end_date', $this->end_date);

            // Exclude the current record when updating
            if ($this->route('financial_year')) {
                $query->where('id', '!=', $this->route('financial_year')->id);
            }

            if ($query->exists()) {
                $validator->errors()->add(
                    'start_date',
                    'A financial year with this date range already exists.'
                );
            }
        });
    }
}
