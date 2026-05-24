<?php

namespace App\Http\Requests\Tenant;

use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SavingsRepaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'savings_account_id' => ['required', 'integer', 'exists:tenant.savings_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date', 'before_or_equal:today'],
            'loan_officer_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * After base validation passes, enforce business rules:
     *  1. The savings account must be active.
     *  2. The account must belong to the same member as the loan.
     *  3. The account balance must cover the requested amount.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $accountId = $this->input('savings_account_id');
            $amount = (float) $this->input('amount', 0);

            /** @var Loan $loan */
            $loan = $this->route('loan');

            if (! $accountId || ! $loan) {
                return;
            }

            $account = SavingsAccount::on('tenant')->find($accountId);

            if (! $account) {
                $v->errors()->add('savings_account_id', 'Savings account not found.');

                return;
            }

            if ($account->status !== 'active') {
                $v->errors()->add('savings_account_id', 'The selected savings account is not active.');
            }

            if ((int) $account->member_id !== (int) $loan->member_id) {
                $v->errors()->add('savings_account_id', 'The savings account does not belong to this loan\'s member.');
            }

            if ((float) $account->balance < $amount) {
                $v->errors()->add('amount', sprintf(
                    'Insufficient balance. Account has %s but %s is required.',
                    number_format((float) $account->balance, 2),
                    number_format($amount, 2),
                ));
            }
        });
    }

    public function messages(): array
    {
        return [
            'savings_account_id.required' => 'Please select a savings account.',
            'savings_account_id.exists' => 'The selected savings account does not exist.',
            'amount.min' => 'Repayment amount must be greater than zero.',
            'payment_date.before_or_equal' => 'Payment date cannot be in the future.',
        ];
    }
}
