<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SavingsSharesBalancesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $asOfDate = $request->input('as_of_date', now()->toDateString());
        $branchId = $request->input('branch_id');

        // Query all members left joined with their total savings and shares up to the given date

        $savingsSubquery = DB::connection('tenant')
            ->table('transactions')
            ->join('savings_accounts', 'transactions.savings_account_id', '=', 'savings_accounts.id')
            ->whereNull('transactions.deleted_at')
            ->whereDate('transactions.transaction_date', '<=', $asOfDate)
            ->select('savings_accounts.member_id')
            ->selectRaw("
                SUM(CASE WHEN transactions.type IN ('deposit', 'transfer_in') THEN transactions.amount ELSE 0 END) -
                SUM(CASE WHEN transactions.type IN ('withdrawal', 'transfer_out', 'charge') THEN transactions.amount ELSE 0 END) as total_savings
            ")
            ->groupBy('savings_accounts.member_id');

        // Assuming shares transactions are tracked with 'share_purchase' type in transactions table tied to member_id
        $sharesSubquery = DB::connection('tenant')
            ->table('transactions')
            ->whereNull('transactions.deleted_at')
            ->whereDate('transactions.transaction_date', '<=', $asOfDate)
            ->whereIn('transactions.type', ['share_purchase'])
            ->select('member_id')
            ->selectRaw('SUM(amount) as total_shares')
            ->groupBy('member_id');

        $query = DB::connection('tenant')
            ->table('members')
            ->leftJoin('branches', 'members.branch_id', '=', 'branches.id')
            ->leftJoinSub($savingsSubquery, 'savings_summary', 'members.id', '=', 'savings_summary.member_id')
            ->leftJoinSub($sharesSubquery, 'shares_summary', 'members.id', '=', 'shares_summary.member_id')
            ->whereNull('members.deleted_at')
            ->select(
                'members.id',
                'members.member_number',
                'members.first_name',
                'members.middle_name',
                'members.last_name',
                'members.status',
                'branches.name as branch_name',
                DB::raw('COALESCE(savings_summary.total_savings, 0) as total_savings'),
                DB::raw('COALESCE(shares_summary.total_shares, 0) as total_shares')
            );

        if ($branchId) {
            $query->where('members.branch_id', $branchId);
        }

        $members = $query->orderBy('members.first_name')->paginate(50);

        // Calculate grand totals for the summary cards
        // To be accurate across all pages, we should run a separate aggregation query
        $grandTotalsQuery = DB::connection('tenant')
            ->table('members')
            ->leftJoinSub($savingsSubquery, 'savings_summary', 'members.id', '=', 'savings_summary.member_id')
            ->leftJoinSub($sharesSubquery, 'shares_summary', 'members.id', '=', 'shares_summary.member_id')
            ->whereNull('members.deleted_at');

        if ($branchId) {
            $grandTotalsQuery->where('members.branch_id', $branchId);
        }

        $grandTotals = $grandTotalsQuery->select(
            DB::raw('SUM(COALESCE(savings_summary.total_savings, 0)) as grand_total_savings'),
            DB::raw('SUM(COALESCE(shares_summary.total_shares, 0)) as grand_total_shares')
        )->first();

        return response()->json([
            'as_of_date' => $asOfDate,
            'summary' => [
                'grand_total_savings' => $grandTotals->grand_total_savings ?? 0,
                'grand_total_shares' => $grandTotals->grand_total_shares ?? 0,
            ],
            'members' => $members,
        ]);
    }
}
