<?php

namespace App\Tenant\Modules\Expenses\Services;

use App\Tenant\Modules\Expenses\Contracts\ExpenseBudgetServiceInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExpenseBudgetService implements ExpenseBudgetServiceInterface
{
    /**
     * Get budgets for a given fiscal year.
     */
    public function getBudgets(string $fiscalYear): Collection
    {
        return DB::connection('tenant')->table('expense_budgets')
            ->select('expense_budgets.*', 'expense_categories.name as category_name')
            ->join('expense_categories', 'expense_budgets.expense_category_id', '=', 'expense_categories.id')
            ->where('fiscal_year', $fiscalYear)
            ->get();
    }

    /**
     * Allocate a budget for an expense category.
     */
    public function allocateBudget(int $categoryId, string $fiscalYear, ?string $periodCode, float $amount, ?int $branchId = null): void
    {
        DB::connection('tenant')->table('expense_budgets')->updateOrInsert(
            [
                'expense_category_id' => $categoryId,
                'branch_id' => $branchId,
                'fiscal_year' => $fiscalYear,
                'period_code' => $periodCode,
            ],
            [
                'allocated_amount' => $amount,
                'updated_at' => now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())')
            ]
        );
    }
}
