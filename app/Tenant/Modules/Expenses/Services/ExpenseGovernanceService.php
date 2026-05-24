<?php

namespace App\Tenant\Modules\Expenses\Services;

use App\Models\User;
use App\Tenant\Modules\Expenses\Enums\ExpenseStatus;
use App\Tenant\Modules\Expenses\Models\Expense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExpenseGovernanceService
{
    /**
     * Check if the expense amount exceeds the budget for the category and period.
     */
    public function checkBudget(Expense $expense): array
    {
        $date = Carbon::parse($expense->transaction_date);
        $period = $date->format('Y-m');
        $fiscalYear = $date->format('Y');

        // Strategy: Look for the most specific budget first
        // 1. Specific Branch + Specific Period
        // 2. Global (Null Branch) + Specific Period
        // 3. Specific Branch + Yearly (Null Period)
        // 4. Global + Yearly
        $budget = DB::connection('tenant')->table('expense_budgets')
            ->where('expense_category_id', $expense->expense_category_id)
            ->where('fiscal_year', $fiscalYear)
            ->where(function ($query) use ($period, $expense) {
                $query->where(function ($q) use ($period, $expense) {
                    $q->where('period_code', $period)
                      ->where('branch_id', $expense->branch_id);
                })
                ->orWhere(function ($q) use ($period) {
                    $q->where('period_code', $period)
                      ->whereNull('branch_id');
                })
                ->orWhere(function ($q) use ($expense) {
                    $q->whereNull('period_code')
                      ->where('branch_id', $expense->branch_id);
                })
                ->orWhere(function ($q) {
                    $q->whereNull('period_code')
                      ->whereNull('branch_id');
                })
                ->orderByRaw('CASE WHEN period_code IS NOT NULL AND branch_id IS NOT NULL THEN 1
                                  WHEN period_code IS NOT NULL AND branch_id IS NULL THEN 2
                                  WHEN period_code IS NULL AND branch_id IS NOT NULL THEN 3
                                  ELSE 4 END ASC');
            })
            ->first();

        if (!$budget) {
            return [
                'allowed' => true,
                'message' => 'No budget defined for this category/period.',
                'remaining' => null,
                'budget_amount' => 0,
                'spent_amount' => 0,
                'is_over_budget' => false,
            ];
        }

        $remaining = (float)$budget->allocated_amount - (float)$budget->spent_amount;
        $isOver = (float)$expense->amount > $remaining;

        return [
            'allowed' => !$isOver,
            'message' => $isOver 
                ? "Budget exceeded. Remaining: " . number_format($remaining, 2)
                : 'Within budget.',
            'remaining' => $remaining - (float)$expense->amount,
            'budget_amount' => (float)$budget->allocated_amount,
            'spent_amount' => (float)$budget->spent_amount,
            'is_over_budget' => $isOver,
        ];
    }

    /**
     * Determine the next required approval level and role.
     */
    public function determineNextApprover(Expense $expense): ?object
    {
        $currentLevel = $expense->current_approval_level ?? 0;

        $next = DB::connection('tenant')->table('expense_approval_thresholds')
            ->where('min_amount', '<=', $expense->amount)
            ->where('level', '>', $currentLevel)
            ->orderBy('level', 'asc')
            ->first();

        // If no more amount-based approvers, but it's over budget, 
        // require final Board/Admin level approval (Level 5)
        if (!$next && $expense->is_over_budget && $currentLevel < 5) {
            return DB::connection('tenant')->table('expense_approval_thresholds')
                ->where('level', 5)
                ->first();
        }

        return $next;
    }

    /**
     * Get the full approval path for a given amount.
     */
    public function getApprovalPath(float $amount, bool $isOverBudget = false): \Illuminate\Support\Collection
    {
        $path = DB::connection('tenant')->table('expense_approval_thresholds')
            ->where('min_amount', '<=', $amount)
            ->orderBy('level', 'asc')
            ->get();

        if ($isOverBudget) {
            $highestLevel = DB::connection('tenant')->table('expense_approval_thresholds')
                ->where('level', 5)
                ->first();

            if ($highestLevel && !$path->contains('level', 5)) {
                $path->push($highestLevel);
            }
        }

        return $path;
    }

    /**
     * Record an approval action in the history.
     */
    public function recordAction(Expense $expense, int $actorId, string $action, ?string $comments = null): void
    {
        DB::connection('tenant')->table('expense_approval_history')->insert([
            'expense_id' => $expense->id,
            'approver_id' => $actorId,
            'level' => $expense->current_approval_level,
            'action' => $action,
            'comments' => $comments,
            'created_at' => now(),
        ]);
    }
}
