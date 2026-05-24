<?php

namespace App\Tenant\Modules\Expenses\Services;

use App\Tenant\Modules\Expenses\Contracts\ExpenseThresholdServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ExpenseThresholdService implements ExpenseThresholdServiceInterface
{
    /**
     * Retrieve all approval thresholds ordered by level.
     */
    public function getThresholds(): Collection
    {
        return DB::connection('tenant')->table('expense_approval_thresholds')
            ->orderBy('level', 'asc')
            ->get();
    }

    /**
     * Update the approval thresholds matrix.
     * Replaces the entire matrix with the new validated input.
     *
     * @param array $thresholds
     * @return void
     */
    public function updateThresholds(array $thresholds): void
    {
        DB::connection('tenant')->transaction(function () use ($thresholds) {
            // Clear existing thresholds (use delete() not truncate() — TRUNCATE is DDL
            // and causes an implicit commit in MySQL, killing the active transaction)
            DB::connection('tenant')->table('expense_approval_thresholds')->delete();

            // Insert new thresholds
            $insertData = collect($thresholds)->map(function ($threshold) {
                return [
                    'level' => $threshold['level'],
                    'min_amount' => $threshold['min_amount'],
                    'max_amount' => $threshold['max_amount'] ?? null,
                    'required_role' => $threshold['required_role'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })->toArray();

            DB::connection('tenant')->table('expense_approval_thresholds')->insert($insertData);
        });
    }
}
