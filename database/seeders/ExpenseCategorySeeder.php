<?php

namespace Database\Seeders;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Expenses\Models\ExpenseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Rent & Occupancy',
                'description' => 'Office rent, lease payments, and occupancy-related charges.',
                'gl_code' => '53100', // Rent & Occupancy
            ],
            [
                'name' => 'Utilities',
                'description' => 'Electricity, water, and internet service charges.',
                'gl_code' => '53200', // Utilities
            ],
            [
                'name' => 'Communications',
                'description' => 'Telephone, internet subscriptions, and postal charges.',
                'gl_code' => '53300', // Communications & Internet
            ],
            [
                'name' => 'Salaries & Wages',
                'description' => 'Staff salaries, bonuses, and statutory payments.',
                'gl_code' => '52100', // Salaries & Wages
            ],
            [
                'name' => 'NSSF & Staff Benefits',
                'description' => 'NSSF employer contributions and other statutory staff benefits.',
                'gl_code' => '52200', // NSSF Contributions
            ],
            [
                'name' => 'Staff Training & Development',
                'description' => 'Workshop fees, training materials, and capacity building.',
                'gl_code' => '52300', // Staff Training
            ],
            [
                'name' => 'Audit & Professional Fees',
                'description' => 'External audit, legal, and consultancy fees.',
                'gl_code' => '53400', // Audit & Professional Fees
            ],
            [
                'name' => 'Regulatory & Licensing Fees',
                'description' => 'Government levies, SACCO regulatory fees, and licences.',
                'gl_code' => '53500', // Regulatory Fees & Levies
            ],
            [
                'name' => 'Board & Committee Expenses',
                'description' => 'Board sittings, AGM costs, and director allowances.',
                'gl_code' => '53600', // Board Allowances
            ],
            [
                'name' => 'Health, Medical & Insurance',
                'description' => 'Staff medical cover, group life insurance, and health-related benefits.',
                'gl_code' => '52400', // Medical & Health Insurance
            ],
            [
                'name' => 'Office Supplies & Stationery',
                'description' => 'Stationery, printer ink, and general office consumables.',
                'gl_code' => '55000', // Other Operating Expenses
            ],
            [
                'name' => 'Travel & Transport',
                'description' => 'Fuel, vehicle maintenance, and staff travel allowances.',
                'gl_code' => '55000', // Other Operating Expenses
            ],
            [
                'name' => 'Marketing & Promotion',
                'description' => 'Advertising, brochures, member outreach, and event costs.',
                'gl_code' => '55000', // Other Operating Expenses
            ],
        ];

        // Rename map: old name → new canonical name
        // Re-points all FK references before deleting the old record
        $renames = [
            'Rent & Utilities' => 'Rent & Occupancy',
            'Office Supplies'  => 'Office Supplies & Stationery',
        ];

        foreach ($renames as $oldName => $newName) {
            $old = ExpenseCategory::where('name', $oldName)->first();
            if (! $old) {
                continue;
            }
            $new = ExpenseCategory::where('name', $newName)->first();
            if ($new) {
                DB::table('expenses')->where('expense_category_id', $old->id)->update(['expense_category_id' => $new->id]);
                DB::table('expense_budgets')->where('expense_category_id', $old->id)->update(['expense_category_id' => $new->id]);
                $old->delete();
            } else {
                $old->update(['name' => $newName]);
            }
        }

        foreach ($categories as $categoryData) {
            $account = ChartOfAccount::where('gl_code', $categoryData['gl_code'])->first();

            if ($account) {
                ExpenseCategory::updateOrCreate(
                    ['name' => $categoryData['name']],
                    [
                        'description' => $categoryData['description'],
                        'chart_of_account_id' => $account->id,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
