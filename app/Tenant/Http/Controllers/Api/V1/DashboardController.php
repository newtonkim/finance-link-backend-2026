<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Scopes\BranchReadScope;
use App\Models\Staff;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get summary metrics for the tenant dashboard.
     *
     * Branch scoping rules:
     * - SCOPE_ALL (tenant admin): sees all branches unless an explicit branch_id
     *   query-param is provided, in which case metrics are scoped to that branch.
     * - SCOPE_BRANCH / SCOPE_SELF staff: metrics are always scoped to their own branch.
     *
     * Member/Staff Eloquent queries benefit from BranchReadScope automatically.
     * Raw DB::table() queries are scoped via the $branchId local variable.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();
        $isAdmin = $user instanceof Staff && BranchContext::scopeFor($user) === BranchContext::SCOPE_ALL;

        // For admins: optional branch filter from request; for staff: always their branch.
        $branchId = $isAdmin
            ? BranchContext::filterBranchId()   // null = show all branches
            : ($user instanceof Staff ? ($user->branch_id ? (int) $user->branch_id : null) : null);

        // Helper to optionally scope a DB query builder to the active branch.
        $scopeRaw = function ($query, string $table) use ($branchId) {
            if ($branchId !== null) {
                $query->where("{$table}.branch_id", $branchId);
            }

            return $query;
        };

        // Eloquent queries (Member, Staff) use BranchReadScope automatically for
        // non-admin staff. For admin + optional branch filter we add a manual where.
        $memberQuery = fn () => $isAdmin && $branchId !== null
            ? Member::withoutGlobalScope(BranchReadScope::class)->where('branch_id', $branchId)
            : Member::query();

        $metrics = [
            'total_members' => $memberQuery()->count(),
            'active_members' => $memberQuery()->where('status', 'active')->count(),
            'total_staff' => $isAdmin && $branchId !== null
                ? Staff::withoutGlobalScope(BranchReadScope::class)->where('branch_id', $branchId)->count()
                : Staff::count(),

            'savings_summary' => [
                'total_deposits' => $scopeRaw(DB::connection('tenant')->table('savings_accounts'), 'savings_accounts')->sum('balance') ?? 0,
                'active_accounts' => $scopeRaw(DB::connection('tenant')->table('savings_accounts'), 'savings_accounts')->count(),
            ],

            'loans_summary' => [
                'active_loans' => $scopeRaw(DB::connection('tenant')->table('loans'), 'loans')->whereIn('status', ['disbursed', 'running'])->count(),
                'total_outstanding' => $scopeRaw(DB::connection('tenant')->table('loans'), 'loans')->whereIn('status', ['disbursed', 'running'])->sum('outstanding_balance') ?? 0,
                'pending_applications' => $scopeRaw(DB::connection('tenant')->table('loans'), 'loans')->where('status', 'pending')->count(),
            ],

            'gender_ratio' => [
                'male' => $memberQuery()->whereNull('deleted_at')->where('gender', 'male')->count(),
                'female' => $memberQuery()->whereNull('deleted_at')->where('gender', 'female')->count(),
            ],

            'total_withdrawals' => $scopeRaw(DB::connection('tenant')->table('transactions'), 'transactions')->where('type', 'withdrawal')->whereNull('deleted_at')->sum('amount') ?? 0,
            'total_deposits' => $scopeRaw(DB::connection('tenant')->table('transactions'), 'transactions')->where('type', 'deposit')->whereNull('deleted_at')->sum('amount') ?? 0,

            'portfolio_mix' => [
                ['label' => 'Members',          'value' => $memberQuery()->whereNull('deleted_at')->count()],
                ['label' => 'Savings Accounts', 'value' => $scopeRaw(DB::connection('tenant')->table('savings_accounts'), 'savings_accounts')->whereNull('deleted_at')->count()],
                ['label' => 'Savings Products', 'value' => DB::connection('tenant')->table('savings_products')->whereNull('deleted_at')->count()],
                ['label' => 'Deposits',         'value' => (float) ($scopeRaw(DB::connection('tenant')->table('transactions'), 'transactions')->where('type', 'deposit')->whereNull('deleted_at')->sum('amount') ?? 0)],
                ['label' => 'Withdrawals',      'value' => (float) ($scopeRaw(DB::connection('tenant')->table('transactions'), 'transactions')->where('type', 'withdrawal')->whereNull('deleted_at')->sum('amount') ?? 0)],
                ['label' => 'Charges',          'value' => (float) ($scopeRaw(DB::connection('tenant')->table('transactions'), 'transactions')->where('type', 'charge')->whereNull('deleted_at')->sum('amount') ?? 0)],
            ],

            'leaderboards' => [
                'savers_by_value' => $scopeRaw(
                    DB::connection('tenant')
                        ->table('savings_accounts')
                        ->join('members', 'savings_accounts.member_id', '=', 'members.id')
                        ->whereNull('savings_accounts.deleted_at')
                        ->whereNull('members.deleted_at'),
                    'savings_accounts'
                )
                    ->select(
                        'members.id',
                        'members.name',
                        'members.member_number',
                        DB::raw('SUM(savings_accounts.balance) as total_balance')
                    )
                    ->groupBy('members.id', 'members.name', 'members.member_number')
                    ->orderByDesc('total_balance')
                    ->limit(5)
                    ->get(),

                'savers_by_frequency' => $scopeRaw(
                    DB::connection('tenant')
                        ->table('transactions')
                        ->join('members', 'transactions.member_id', '=', 'members.id')
                        ->where('transactions.type', 'deposit')
                        ->whereNull('transactions.deleted_at')
                        ->whereNull('members.deleted_at'),
                    'transactions'
                )
                    ->select(
                        'members.id',
                        'members.name',
                        'members.member_number',
                        DB::raw('COUNT(*) as deposit_count'),
                        DB::raw('SUM(transactions.amount) as total_deposited')
                    )
                    ->groupBy('members.id', 'members.name', 'members.member_number')
                    ->orderByDesc('deposit_count')
                    ->limit(5)
                    ->get(),

                'borrowers_by_value' => $scopeRaw(
                    DB::connection('tenant')
                        ->table('loans')
                        ->join('members', 'loans.member_id', '=', 'members.id')
                        ->whereNull('loans.deleted_at')
                        ->whereNull('members.deleted_at'),
                    'loans'
                )
                    ->select(
                        'members.id',
                        'members.name',
                        'members.member_number',
                        DB::raw('SUM(loans.principal) as total_borrowed'),
                        DB::raw('COUNT(*) as loan_count')
                    )
                    ->groupBy('members.id', 'members.name', 'members.member_number')
                    ->orderByDesc('total_borrowed')
                    ->limit(5)
                    ->get(),

                'borrowers_by_frequency' => $scopeRaw(
                    DB::connection('tenant')
                        ->table('loans')
                        ->join('members', 'loans.member_id', '=', 'members.id')
                        ->whereNull('loans.deleted_at')
                        ->whereNull('members.deleted_at'),
                    'loans'
                )
                    ->select(
                        'members.id',
                        'members.name',
                        'members.member_number',
                        DB::raw('COUNT(*) as loan_count'),
                        DB::raw('SUM(loans.principal) as total_borrowed')
                    )
                    ->groupBy('members.id', 'members.name', 'members.member_number')
                    ->orderByDesc('loan_count')
                    ->limit(5)
                    ->get(),
            ],

            'recent_activity' => [], // To be implemented with auditing or logs
            'branch_context' => BranchContext::authContextFor($user instanceof Staff ? $user : null),
        ];

        return response()->json($metrics);
    }
}
