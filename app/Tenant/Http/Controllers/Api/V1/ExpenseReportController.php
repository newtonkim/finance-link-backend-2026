<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Response;

class ExpenseReportController extends Controller
{
    /**
     * GET /api/expenses/reports/ledger
     */
    public function ledger(Request $request): JsonResponse
    {
        $period = $request->query('period', Carbon::now()->format('Y-m'));
        $branchId = $request->header('X-Acting-Branch-Id');

        $query = DB::connection('tenant')->table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('expenses as e', 'e.id', '=', 'je.reference_id') 
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->where('je.fiscal_period', $period)
            ->where('je.reference_type', 'expense')
            ->select([
                'coa.code as account_code',
                'coa.name as account_name',
                'e.reference_no',
                'e.vendor_name as vendor',
                'e.description',
                'e.transaction_date as payment_date',
                'jl.debit',
                'jl.credit',
                'e.status'
            ])
            ->orderBy('e.transaction_date')
            ->orderBy('coa.code');

        if ($branchId) {
            $query->where('je.branch_id', $branchId);
        }

        return Response::json([
            'data' => $query->get(),
            'message' => 'Expense ledger retrieved successfully.'
        ]);
    }

    /**
     * GET /api/expenses/reports/variance
     */
    public function variance(Request $request): JsonResponse
    {
        $fiscalYear = $request->query('fiscal_year', Carbon::now()->format('Y'));
        $period = $request->query('period', Carbon::now()->format('Y-m'));
        $branchId = $request->header('X-Acting-Branch-Id');

        $query = DB::connection('tenant')->table('expense_budgets as b')
            ->join('expense_categories as ec', 'ec.id', '=', 'b.expense_category_id')
            ->leftJoin('expenses as e', function($join) use ($period, $branchId) {
                $join->on('e.expense_category_id', '=', 'b.expense_category_id')
                    ->whereIn('e.status', ['approved', 'paid', 'reconciled'])
                    ->where(DB::raw("DATE_FORMAT(e.transaction_date, '%Y-%m')"), '=', $period);
                
                if ($branchId) {
                    $join->where('e.branch_id', '=', $branchId);
                }
            })
            ->where('b.fiscal_year', $fiscalYear)
            ->where(function($q) use ($period) {
                $q->where('b.period_code', $period)
                  ->orWhereNull('b.period_code');
            })
            ->select([
                'ec.name as category',
                'b.allocated_amount as budget',
                DB::raw('COALESCE(SUM(e.amount), 0) as actual'),
                DB::raw('b.allocated_amount - COALESCE(SUM(e.amount), 0) as variance'),
                DB::raw('ROUND(COALESCE(SUM(e.amount), 0) / b.allocated_amount * 100, 1) as utilisation_pct')
            ])
            ->groupBy('ec.name', 'b.allocated_amount')
            ->orderByDesc('utilisation_pct');

        if ($branchId) {
            $query->where('b.branch_id', $branchId);
        }

        return Response::json([
            'data' => $query->get(),
            'message' => 'Budget variance report retrieved successfully.'
        ]);
    }

    /**
     * GET /api/expenses/reports/unreconciled
     */
    public function unreconciled(Request $request): JsonResponse
    {
        $branchId = $request->header('X-Acting-Branch-Id');

        $query = DB::connection('tenant')->table('expenses as e')
            ->where('e.status', 'paid')
            ->whereNull('e.bank_statement_line_id')
            ->where('e.transaction_date', '<', Carbon::now()->subDays(7))
            ->select([
                'e.reference_no',
                'e.vendor_name as vendor',
                'e.description',
                'e.amount',
                'e.transaction_date as payment_date',
                'e.payment_method',
                DB::raw('DATEDIFF(CURRENT_DATE, e.transaction_date) as days_outstanding')
            ])
            ->orderByDesc('days_outstanding');

        if ($branchId) {
            $query->where('e.branch_id', $branchId);
        }

        return Response::json([
            'data' => $query->get(),
            'message' => 'Unreconciled expenses retrieved successfully.'
        ]);
    }

    /**
     * GET /api/expenses/reports/branch-breakdown
     */
    public function byBranch(Request $request): JsonResponse
    {
        $period = $request->query('period', Carbon::now()->format('Y-m'));

        $data = DB::connection('tenant')->table('expenses as e')
            ->join('branches as b', 'b.id', '=', 'e.branch_id')
            ->where(DB::raw("DATE_FORMAT(e.transaction_date, '%Y-%m')"), '=', $period)
            ->whereIn('e.status', ['approved', 'paid', 'reconciled'])
            ->select([
                'b.name as branch',
                DB::raw('COUNT(e.id) as transaction_count'),
                DB::raw('SUM(e.amount) as total_amount'),
                DB::raw('ROUND(SUM(e.amount) / (SELECT SUM(amount) FROM expenses WHERE DATE_FORMAT(transaction_date, "%Y-%m") = "'.$period.'" AND status IN ("approved", "paid", "reconciled")) * 100, 1) as percentage_contribution')
            ])
            ->groupBy('b.name')
            ->get();

        return Response::json([
            'data' => $data,
            'message' => 'Branch cost breakdown retrieved successfully.'
        ]);
    }

    /**
     * GET /api/expenses/reports/ie-extract
     */
    public function incomeExpenditureExtract(Request $request): JsonResponse
    {
        $fiscalYear = $request->query('fiscal_year', Carbon::now()->format('Y'));

        $data = DB::connection('tenant')->table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jl.account_id')
            ->where('je.fiscal_year', $fiscalYear)
            ->where('je.reference_type', 'expense')
            ->where('jl.debit', '>', 0)
            ->select([
                'coa.name as expense_line',
                'coa.code as account_code',
                DB::raw('SUM(jl.debit) as total_annual_spend')
            ])
            ->groupBy('coa.name', 'coa.code')
            ->orderBy('coa.code')
            ->get();

        return Response::json([
            'data' => $data,
            'message' => 'Income & Expenditure extract retrieved successfully.'
        ]);
    }

    /**
     * GET /api/expenses/reports/trial-balance-contribution
     */
    public function trialBalanceContribution(Request $request): JsonResponse
    {
        $asOfDate = $request->query('as_of', Carbon::now()->format('Y-m-d'));

        $data = DB::connection('tenant')->table('chart_of_accounts as coa')
            ->join('journal_lines as jl', 'jl.account_id', '=', 'coa.id')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.transaction_date', '<=', $asOfDate)
            ->where('coa.account_type', 'expense')
            ->select([
                'coa.code',
                'coa.name',
                DB::raw('SUM(jl.debit - jl.credit) as closing_balance')
            ])
            ->groupBy('coa.code', 'coa.name')
            ->having('closing_balance', '!=', 0)
            ->get();

        return Response::json([
            'data' => $data,
            'message' => 'Trial Balance contribution (Expense side) retrieved successfully.'
        ]);
    }
}
