<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\AllocateExpenseBudgetRequest;
use App\Tenant\Modules\Expenses\Contracts\ExpenseBudgetServiceInterface;
use App\Tenant\Modules\Expenses\Enums\ExpenseStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class ExpenseBudgetController extends Controller
{
    public function __construct(
        protected ExpenseBudgetServiceInterface $budgetService
    ) {}

    /**
     * GET /api/v1/tenant/expenses/budgets
     */
    public function index(Request $request): JsonResponse
    {
        $fiscalYear = $request->query('fiscal_year', date('Y'));
        
        $budgets = $this->budgetService->getBudgets($fiscalYear);

        return Response::json([
            'data' => $budgets,
            'message' => 'Expense budgets retrieved successfully.'
        ]);
    }

    /**
     * POST /api/v1/tenant/expenses/budgets
     */
    public function store(AllocateExpenseBudgetRequest $request): JsonResponse
    {
        $allocations = $request->validated('allocations');
        
        DB::connection('tenant')->transaction(function () use ($allocations) {
            foreach ($allocations as $allocation) {
                $this->budgetService->allocateBudget(
                    $allocation['expense_category_id'],
                    $allocation['fiscal_year'],
                    $allocation['period_code'] ?? null,
                    $allocation['allocated_amount'],
                    $allocation['branch_id'] ?? null
                );
            }
        });

        return Response::json([
            'message' => 'Expense budgets allocated successfully.'
        ]);
    }

    /**
     * GET /api/v1/tenant/expenses/budgets/check
     */
    public function check(Request $request): JsonResponse
    {
        $categoryId = $request->query('expense_category_id');
        $amount = (float) $request->query('amount', 0);
        $transactionDate = $request->query('transaction_date', date('Y-m-d'));
        $branchId = $request->header('X-Acting-Branch-Id');

        if (!$categoryId) {
            return Response::json(['message' => 'Category ID is required.'], 422);
        }

        // Mock an expense object to use the governance service check
        $expense = new \App\Tenant\Modules\Expenses\Models\Expense([
            'expense_category_id' => $categoryId,
            'amount' => $amount,
            'transaction_date' => $transactionDate,
            'branch_id' => $branchId,
        ]);

        $governanceService = app(\App\Tenant\Modules\Expenses\Services\ExpenseGovernanceService::class);
        $accountingService = app(\App\Tenant\Modules\Expenses\Services\ExpenseAccountingService::class);
        
        $result = $governanceService->checkBudget($expense);
        $journalPreview = $accountingService->previewJournalEntry($expense);

        $category = \App\Tenant\Modules\Expenses\Models\ExpenseCategory::with('chartOfAccount')->find($categoryId);

        return Response::json([
            'data' => [
                'budget_amount' => $result['budget_amount'],
                'spent_amount' => $result['spent_amount'],
                'projected_amount' => $result['spent_amount'] + $amount,
                'remaining_budget' => $result['remaining'] + $amount, 
                'is_over_budget' => $result['is_over_budget'],
                'gl_account_code' => $category?->chartOfAccount?->gl_code,
                'gl_account_name' => $category?->chartOfAccount?->name,
                'journal_preview' => $journalPreview
            ]
        ]);
    }
}
