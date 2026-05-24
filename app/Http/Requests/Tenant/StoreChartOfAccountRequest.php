<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreChartOfAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gl_code' => 'required|string|max:20|unique:tenant.chart_of_accounts,gl_code',
            'name' => 'required|string|max:255',
            'account_type' => 'required|in:ASSET,LIABILITY,EQUITY,INCOME,EXPENSE',
            'normal_balance' => 'required|in:DR,CR',
            'parent_id' => 'nullable|exists:tenant.chart_of_accounts,id',
            'is_control' => 'boolean',
            'is_postable' => 'boolean',
            'is_active' => 'boolean',
            'allow_manual' => 'boolean',
            'account_subtype' => 'nullable|string|max:100',
            'ifrs_category' => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer',
        ];
    }
}
