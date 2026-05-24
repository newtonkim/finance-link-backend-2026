<?php

namespace App\Tenant\Modules\Expenses\Contracts;

interface ExpenseBudgetServiceInterface
{
    /**
     * Get budgets for a given fiscal year.
     */
    public function getBudgets(string $fiscalYear): \Illuminate\Support\Collection;

    /**
     * Allocate a budget for an expense category.
     */
    public function allocateBudget(int $categoryId, string $fiscalYear, ?string $periodCode, float $amount, ?int $branchId = null): void;
}
