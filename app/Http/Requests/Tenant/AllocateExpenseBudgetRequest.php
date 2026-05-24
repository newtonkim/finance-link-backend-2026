<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class AllocateExpenseBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.expense_category_id' => ['required', 'integer', 'exists:tenant.expense_categories,id'],
            'allocations.*.branch_id' => ['nullable', 'integer'],
            'allocations.*.fiscal_year' => ['required', 'string', 'size:4'],
            'allocations.*.period_code' => ['nullable', 'string', 'size:7'], // e.g. 2026-05
            'allocations.*.allocated_amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
