<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseThresholdsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization is handled by middleware (e.g., role checks)
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'thresholds' => ['required', 'array', 'min:1'],
            'thresholds.*.level' => ['required', 'integer', 'min:1', 'distinct'],
            'thresholds.*.min_amount' => ['required', 'numeric', 'min:0'],
            'thresholds.*.max_amount' => ['nullable', 'numeric', 'gt:thresholds.*.min_amount'],
            'thresholds.*.required_role' => ['required', 'string', 'max:255'], // Could validate against Spatie roles table if needed
        ];
    }
    
    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $thresholds = collect($this->input('thresholds'))->sortBy('level')->values();
            
            // Validate continuous gaps and logical overlap
            for ($i = 0; $i < $thresholds->count() - 1; $i++) {
                $current = $thresholds[$i];
                $next = $thresholds[$i + 1];
                
                if (isset($current['max_amount']) && $current['max_amount'] >= $next['min_amount']) {
                    $validator->errors()->add(
                        "thresholds.{$i}.max_amount",
                        "Level {$current['level']} max amount must be strictly less than Level {$next['level']} min amount."
                    );
                }
            }
        });
    }
}
