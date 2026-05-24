<?php

namespace App\Http\Requests\Tenant;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Foundation\Http\FormRequest;

class SavingsProductFormRequest extends FormRequest
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
        $isFixed = $this->input('type') === 'fixed';

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:fixed,standard'],
            'minimum_balance' => ['required', 'numeric', 'min:0'],
            'minimum_maturity_months' => ['required', 'integer', 'min:0'],
            'dormancy_period_months' => ['required', 'integer', 'min:0'],
            'charge_on_deposit' => ['boolean'],
            'charge_on_withdraw' => ['boolean'],
            'charge_on_transfer' => ['boolean'],
            'status' => ['in:active,inactive'],
            'monthly_fee_enabled' => ['boolean'],
            'monthly_fee_type' => ['nullable', 'in:percentage,amount'],
            'monthly_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'monthly_fee_deduction_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'charges' => ['nullable', 'array'],
            'charges.*.name' => ['nullable', 'string', 'max:255'],
            'charges.*.type' => ['required', 'in:deposit,withdraw,transfer'],
            'charges.*.minimum_amount' => ['required', 'numeric', 'min:0'],
            'charges.*.maximum_amount' => ['nullable', 'numeric', 'min:0'],
            'charges.*.charge_type' => ['required', 'in:percentage,amount'],
            'charges.*.amount' => ['required', 'numeric', 'min:0'],
            'charges.*.is_reversible' => ['boolean'],
            // Loyalty fields
            'loyalty_fee_enabled' => ['boolean'],
            'loyalty_adjustment_type' => ['nullable', 'in:discount_percentage,fixed_discount,custom_fee'],
            'loyalty_adjustment_value' => ['nullable', 'numeric', 'min:0'],
            // FD-only fields
            'interest_rate' => [$isFixed ? 'required' : 'nullable', 'numeric', 'min:0', 'max:100'],
            'interest_payout_type' => [$isFixed ? 'required' : 'nullable', 'in:at_maturity,periodic_payout,compound'],
            'interest_posting_frequency' => ['nullable', 'in:monthly,quarterly,semi_annually,annually'],
            'default_tenor_months' => [$isFixed ? 'required' : 'nullable', 'integer', 'min:1'],
            'maturity_action' => [$isFixed ? 'required' : 'nullable', 'in:auto_rollover,manual,convert_to_savings'],
            'convert_to_product_id' => ['nullable', 'integer', 'exists:savings_products,id'],
            'interest_expense_account_id' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    $account = ChartOfAccount::find($value);
                    if ($account && $account->normal_balance !== 'DR') {
                        $fail('The interest expense account must have a Debit (DR) normal balance.');
                    }
                },
            ],
            'interest_payable_account_id' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    $account = ChartOfAccount::find($value);
                    if ($account && $account->normal_balance !== 'CR') {
                        $fail('The interest payable account must have a Credit (CR) normal balance.');
                    }
                },
            ],
        ];
    }
}
