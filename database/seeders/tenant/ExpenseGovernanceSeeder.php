<?php

namespace Database\Seeders\Tenant;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ExpenseGovernanceSeeder extends Seeder
{
    public function run(): void
    {
        // Clear existing
        DB::table('expense_approval_thresholds')->truncate();

        $thresholds = [
            [
                'min_amount' => 0,
                'max_amount' => 500000,
                'required_role' => 'accountant',
                'level' => 1,
            ],
            [
                'min_amount' => 500000.01,
                'max_amount' => 2000000,
                'required_role' => 'branch_manager',
                'level' => 2,
            ],
            [
                'min_amount' => 2000000.01,
                'max_amount' => 5000000,
                'required_role' => 'admin',
                'level' => 3,
            ],
            [
                'min_amount' => 5000000.01,
                'max_amount' => 10000000,
                'required_role' => 'treasurer',
                'level' => 4,
            ],
            [
                'min_amount' => 10000000.01,
                'max_amount' => null,
                'required_role' => 'board',
                'level' => 5,
            ],
        ];

        foreach ($thresholds as $threshold) {
            DB::table('expense_approval_thresholds')->insert(array_merge($threshold, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }
}
