<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entry_date' => 'required|date',
            'period' => 'nullable|date_format:Y-m',
            'entry_type' => 'nullable|string|in:MANUAL,ADJUSTING,OPENING,CLOSING,REVERSAL',
            'currency' => 'nullable|string|max:10',
            'status' => 'nullable|string|in:draft,posted',
            'reference' => 'nullable|string|max:255',
            'description' => 'required|string|max:1000',
            'lines' => 'required|array|min:2',
            'lines.*.chart_of_account_id' => 'required|exists:tenant.chart_of_accounts,id',
            'lines.*.description' => 'nullable|string|max:1000',
            'lines.*.cost_centre' => 'nullable|string|max:255',
            'lines.*.debit_amount' => 'nullable|numeric|min:0',
            'lines.*.credit_amount' => 'nullable|numeric|min:0',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $lines = collect($this->input('lines', []));
            $totalDebit = 0.0;
            $totalCredit = 0.0;

            foreach ($lines as $index => $line) {
                $debit = (float) ($line['debit_amount'] ?? 0);
                $credit = (float) ($line['credit_amount'] ?? 0);

                if ($debit <= 0 && $credit <= 0) {
                    $validator->errors()->add("lines.{$index}.debit_amount", 'Enter either a debit or a credit amount.');
                }

                if ($debit > 0 && $credit > 0) {
                    $validator->errors()->add("lines.{$index}.credit_amount", 'A line cannot have both debit and credit amounts.');
                }

                $totalDebit += $debit;
                $totalCredit += $credit;
            }

            if ($this->input('status', 'posted') === 'posted' && round($totalDebit, 2) !== round($totalCredit, 2)) {
                $validator->errors()->add('lines', 'Total debits must equal total credits.');
            }
        });
    }
}
