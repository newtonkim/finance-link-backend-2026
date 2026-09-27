<?php

namespace App\Tenant\Modules\Accounting\Support;

final class IncomeStatementLines
{
    public const DEFINITIONS = [
        'interest_income' => ['Interest income', 'INCOME'],
        'interest_expense' => ['Interest expense', 'EXPENSE'],
        'fee_income' => ['Fee and commission income', 'INCOME'],
        'other_income' => ['Other operating income', 'INCOME'],
        'impairment' => ['Credit impairment charge / (reversal)', 'EXPENSE'],
        'staff_costs' => ['Staff costs', 'EXPENSE'],
        'administration' => ['Administrative expenses', 'EXPENSE'],
        'depreciation' => ['Depreciation and amortisation', 'EXPENSE'],
        'other_expenses' => ['Other operating expenses', 'EXPENSE'],
        'investment_income' => ['Investment result', 'INCOME'],
        'income_tax' => ['Income tax expense / (credit)', 'EXPENSE'],
    ];

    // Exact template identities only: a tenant's custom account is never guessed from its code.
    public const TEMPLATE = [
        '41000' => ['Interest Income', 'interest_income'],
        '41100' => ['Interest on Personal Loans', 'interest_income'],
        '41101' => ['Accrued Interest – Personal Loans', 'interest_income'],
        '41102' => ['Cash Interest – Personal Loans', 'interest_income'],
        '41200' => ['Interest on Business Loans', 'interest_income'],
        '41300' => ['Interest on Agriculture Loans', 'interest_income'],
        '41400' => ['Penalty / Default Interest', 'interest_income'],
        '42000' => ['Fee Income', 'fee_income'],
        '42100' => ['Loan Application Fees', 'fee_income'],
        '42200' => ['Loan Processing Fees', 'fee_income'],
        '42250' => ['Loan Charges Income', 'fee_income'],
        '42300' => ['Account Maintenance Fees', 'fee_income'],
        '42400' => ['Late Payment Penalties', 'fee_income'],
        '43000' => ['Other Operating Income', 'other_income'],
        '44000' => ['Investment Income', 'investment_income'],
        '51100' => ['Interest Expense on Savings', 'interest_expense'],
        '51200' => ['Interest Expense on Borrowings', 'interest_expense'],
        '51300' => ['ECL Provision Expense (IFRS 9)', 'impairment'],
        '51301' => ['ECL Charge – Stage 1', 'impairment'],
        '51302' => ['ECL Charge – Stage 2', 'impairment'],
        '51303' => ['ECL Charge – Stage 3', 'impairment'],
        '51304' => ['Loan Write-off Expense', 'impairment'],
        '52000' => ['Staff Costs', 'staff_costs'],
        '52100' => ['Salaries & Wages', 'staff_costs'],
        '52200' => ['NSSF Contributions', 'staff_costs'],
        '52300' => ['Staff Training', 'staff_costs'],
        '52400' => ['Medical & Health Insurance', 'staff_costs'],
        '53000' => ['Administrative Expenses', 'administration'],
        '53100' => ['Rent & Occupancy', 'administration'],
        '53200' => ['Utilities', 'administration'],
        '53300' => ['Communications & Internet', 'administration'],
        '53400' => ['Audit & Professional Fees', 'administration'],
        '53500' => ['Regulatory Fees & Levies', 'administration'],
        '53600' => ['Board Allowances', 'administration'],
        '54000' => ['Depreciation & Amortisation', 'depreciation'],
        '54100' => ['Depreciation – Buildings', 'depreciation'],
        '54200' => ['Depreciation – Motor Vehicles', 'depreciation'],
        '54300' => ['Depreciation – IT Equipment', 'depreciation'],
        '54400' => ['Amortisation – Software', 'depreciation'],
        '55000' => ['Other Operating Expenses', 'other_expenses'],
        '56000' => ['Tax Expense', 'income_tax'],
    ];

    public static function bootstrap(string $code, string $name, string $type): ?string
    {
        $entry = self::TEMPLATE[$code] ?? null;

        return $entry && $entry[0] === $name && self::DEFINITIONS[$entry[1]][1] === $type ? $entry[1] : null;
    }

    public static function keysFor(string $type): array
    {
        return array_keys(array_filter(self::DEFINITIONS, fn ($line) => $line[1] === $type));
    }
}
