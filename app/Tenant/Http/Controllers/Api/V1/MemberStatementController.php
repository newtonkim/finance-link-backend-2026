<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Support\TenantMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberStatementController extends Controller
{
    public function show(Request $request, int $memberId): JsonResponse
    {
        // 1. Fetch Member Info
        $member = DB::connection('tenant')
            ->table('members')
            ->leftJoin('branches', 'members.branch_id', '=', 'branches.id')
            ->select('members.id', 'members.member_number', 'members.first_name', 'members.middle_name', 'members.last_name', 'members.status', 'branches.name as branch_name')
            ->where('members.id', $memberId)
            ->whereNull('members.deleted_at')
            ->first();

        if (! $member) {
            return response()->json(['message' => 'Member not found'], 404);
        }

        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        // 2. Fetch Aggregated Balances

        // A. Savings Balance
        $savingsQuery = DB::connection('tenant')
            ->table('transactions')
            ->join('savings_accounts', 'transactions.savings_account_id', '=', 'savings_accounts.id')
            ->where('savings_accounts.member_id', $memberId)
            ->whereNull('transactions.group_savings_account_id')
            ->whereNull('transactions.deleted_at');

        if ($dateTo) {
            $savingsQuery->whereDate('transactions.transaction_date', '<=', $dateTo);
        }

        $savingsResult = $savingsQuery->selectRaw("
            SUM(CASE WHEN transactions.type IN ('deposit', 'transfer_in') THEN transactions.amount ELSE 0 END) -
            SUM(CASE WHEN transactions.type IN ('withdrawal', 'transfer_out', 'charge') THEN transactions.amount ELSE 0 END) as total_savings
        ")->first();

        $totalSavings = $savingsResult->total_savings ?? 0;

        // B. Shares Balance
        // Check if shares table / accounts exist. Assuming shares might be in savings_accounts with a specific product type,
        // or a separate 'share_accounts' table. For now we will structure it to query 'share_accounts' or transactions for shares.
        // Let's use transactions where type = 'share_purchase' or 'share_transfer_in' as a placeholder pattern.
        $sharesQuery = DB::connection('tenant')
            ->table('transactions')
            ->where('member_id', $memberId)
            ->whereIn('type', ['share_purchase']) // Adjust based on actual type
            ->whereNull('group_savings_account_id')
            ->whereNull('deleted_at');

        if ($dateTo) {
            $sharesQuery->whereDate('transaction_date', '<=', $dateTo);
        }

        $totalShares = $sharesQuery->sum('amount');

        // C. Loans Balance
        $loansQuery = DB::connection('tenant')
            ->table('loans')
            ->where('member_id', $memberId)
            ->whereIn('status', ['disbursed', 'running'])
            ->whereNull('deleted_at');

        if ($dateTo) {
            // Approximation for Point-in-Time loan balance is complex without a full ledger.
            // For now, we return current outstanding balance if no date filter, or calculate.
            // Simplified: we'll return the current outstanding balance.
            $loansQuery->whereDate('created_at', '<=', $dateTo);
        }

        $totalLoans = $loansQuery->sum('outstanding_balance');

        // 3. Fetch Transactions Ledger
        $transactionsQuery = DB::connection('tenant')
            ->table('transactions')
            ->where('member_id', $memberId)
            ->whereNull('deleted_at');

        // Also include transactions tied to member's savings accounts and loans
        // We'll use a subquery or OR conditions to ensure we get everything
        $transactionsQuery = DB::connection('tenant')
            ->table('transactions')
            ->leftJoin('savings_accounts', 'transactions.savings_account_id', '=', 'savings_accounts.id')
            ->leftJoin('loans', 'transactions.loan_id', '=', 'loans.id')
            ->where(function ($q) use ($memberId) {
                $q->where('transactions.member_id', $memberId)
                    ->orWhere('savings_accounts.member_id', $memberId)
                    ->orWhere('loans.member_id', $memberId);
            })
            ->whereNull('transactions.group_savings_account_id')
            ->whereNull('transactions.deleted_at')
            ->select(
                'transactions.id',
                'transactions.transaction_date',
                'transactions.type',
                'transactions.amount',
                'transactions.description',
                'transactions.reference',
                'transactions.savings_account_id',
                'transactions.loan_id'
            );

        if ($dateFrom) {
            $transactionsQuery->whereDate('transactions.transaction_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $transactionsQuery->whereDate('transactions.transaction_date', '<=', $dateTo);
        }

        $transactions = $transactionsQuery->orderBy('transactions.transaction_date', 'desc')
            ->orderBy('transactions.id', 'desc')
            ->paginate(50);

        $transactions->getCollection()->transform(function ($transaction) {
            $transaction->amount_formatted = TenantMoney::format($transaction->amount);

            return $transaction;
        });

        return response()->json([
            'member' => $member,
            'summary' => [
                'total_savings' => $totalSavings,
                'total_savings_formatted' => TenantMoney::format($totalSavings),
                'total_shares' => $totalShares,
                'total_shares_formatted' => TenantMoney::format($totalShares),
                'total_loans' => $totalLoans,
                'total_loans_formatted' => TenantMoney::format($totalLoans),
                'currency_code' => TenantMoney::code(),
            ],
            'transactions' => $transactions,
        ]);
    }
}
