<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Staff;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;

class LoanBalancesReportService
{
    public function getReport(array $filters): array
    {
        $asOf = $filters['as_of_date'] ?? now()->toDateString();
        $perPage = max(1, (int) ($filters['per_page'] ?? 10));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $baseQuery = $this->baseQuery($asOf, $filters);
        $summary = $this->calculateSummary($baseQuery);
        $countQuery = clone $baseQuery;

        $loans = $baseQuery
            ->selectRaw('l.id as loan_id')
            ->selectRaw('l.loan_no')
            ->selectRaw('l.status')
            ->selectRaw('l.disbursed_at')
            ->selectRaw('l.loan_officer_id')
            ->selectRaw('l.branch_id')
            ->selectRaw('l.loan_product_id')
            ->selectRaw('m.id as member_id')
            ->selectRaw('m.name as member_name')
            ->selectRaw('m.member_number')
            ->selectRaw('COALESCE(p.name, \'\') as product_name')
            ->selectRaw('COALESCE(b.name, \'Unassigned\') as branch_name')
            ->selectRaw('COALESCE(s.name, \'—\') as loan_officer_name')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.principal_due - rs.principal_paid)), 0) as principal_remaining')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.interest_due - rs.interest_paid)), 0) as interest_remaining')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.charges_due - rs.charges_paid)), 0) as charges_remaining')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.penalty_due - rs.penalty_paid)), 0) as penalty_remaining')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due) - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid)), 0) as total_balance')
            ->selectRaw('MAX(CASE WHEN rs.due_date < ? AND rs.status != \'paid\' THEN DATEDIFF(?, rs.due_date) ELSE 0 END) as days_in_arrears', [$asOf, $asOf])
            ->selectRaw('MIN(CASE WHEN rs.status != \'paid\' AND rs.due_date >= ? THEN rs.due_date END) as next_due_date', [$asOf])
            ->groupBy('l.id', 'l.loan_no', 'l.status',
                'l.disbursed_at', 'l.loan_officer_id', 'l.branch_id', 'l.loan_product_id',
                'm.id', 'm.name', 'm.member_number', 'p.name', 'b.name', 's.name')
            ->orderByDesc('total_balance')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $totalCount = $countQuery->distinct()->count('l.id');

        return [
            'summary' => $summary,
            'loans' => [
                'data' => $loans->map(fn ($loan) => [
                    'loan_id' => $loan->loan_id,
                    'loan_no' => $loan->loan_no,
                    'member_id' => $loan->member_id,
                    'member_name' => $loan->member_name,
                    'member_number' => $loan->member_number,
                    'branch_name' => $loan->branch_name,
                    'loan_officer_name' => $loan->loan_officer_name,
                    'product_name' => $loan->product_name,
                    'principal' => $loan->principal_remaining,
                    'interest_remaining' => $loan->interest_remaining,
                    'charges_remaining' => $loan->charges_remaining,
                    'penalty_remaining' => $loan->penalty_remaining,
                    'outstanding_balance' => $loan->total_balance,
                    'status' => $this->mapStatus($loan->status),
                    'disbursed_at' => $loan->disbursed_at,
                    'next_due_date' => $loan->next_due_date,
                    'days_in_arrears' => $loan->days_in_arrears > 0 ? (int) $loan->days_in_arrears : null,
                ]),
                'meta' => [
                    'current_page' => $page,
                    'last_page' => (int) ceil($totalCount / $perPage),
                    'per_page' => $perPage,
                    'total' => $totalCount,
                    'from' => $offset + 1,
                    'to' => min($offset + $perPage, $totalCount),
                ],
            ],
        ];
    }

    protected function baseQuery(string $asOf, array $filters)
    {
        $disbursedStatuses = ['disbursed', 'active', 'running', 'arrears', 'closed', 'fully_repaid', 'written_off', 'defaulted'];

        $query = DB::connection('tenant')
            ->table('loans as l')
            ->join('members as m', 'm.id', '=', 'l.member_id')
            ->leftJoin('loan_products as p', 'p.id', '=', 'l.loan_product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->leftJoin('staff as s', 's.id', '=', 'l.loan_officer_id')
            ->leftJoin('loan_repayment_schedule as rs', 'rs.loan_id', '=', 'l.id')
            ->whereNull('l.deleted_at')
            ->whereNull('m.deleted_at')
            ->where(function ($q) use ($asOf, $disbursedStatuses) {
                $q->whereDate('l.disbursed_at', '<=', $asOf)
                    ->orWhere(function ($q2) use ($disbursedStatuses) {
                        // Include loans missing disbursed_at if their status confirms they were disbursed
                        $q2->whereNull('l.disbursed_at')
                            ->whereIn('l.status', $disbursedStatuses);
                    });
            });

        if (! empty($filters['branch_id'])) {
            $query->where('l.branch_id', $filters['branch_id']);
        }

        if (! empty($filters['loan_product_id'])) {
            $query->where('l.loan_product_id', $filters['loan_product_id']);
        }

        if (! empty($filters['loan_officer_id'])) {
            $query->where('l.loan_officer_id', $filters['loan_officer_id']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $statuses = $this->mapStatusFilter($filters['status']);
            if (is_array($statuses)) {
                $query->whereIn('l.status', $statuses);
            } else {
                $query->where('l.status', $statuses);
            }
        }

        $this->applyBranchScope($query);

        return $query;
    }

    protected function calculateSummary($baseQuery)
    {
        // Aggregate remaining balances per loan (not original disbursed amounts).
        // A loan balance report shows what is still owed, not what was originally lent.
        $perLoanQuery = (clone $baseQuery)
            ->selectRaw('l.id')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.principal_due - rs.principal_paid)), 0) as principal_remaining')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.interest_due - rs.interest_paid)), 0) as interest_remaining')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.charges_due - rs.charges_paid)), 0) as charges_remaining')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.penalty_due - rs.penalty_paid)), 0) as penalty_remaining')
            ->groupBy('l.id');

        $summary = DB::connection('tenant')
            ->query()
            ->fromSub($perLoanQuery, 'agg')
            ->selectRaw('COALESCE(SUM(agg.principal_remaining), 0) as total_principal')
            ->selectRaw('COALESCE(SUM(agg.interest_remaining), 0) as total_interest')
            ->selectRaw('COALESCE(SUM(agg.charges_remaining), 0) as total_charges')
            ->selectRaw('COALESCE(SUM(agg.penalty_remaining), 0) as total_penalty')
            ->selectRaw('COUNT(agg.id) as loan_count')
            ->first();

        $grandTotal = $summary->total_principal
            + $summary->total_interest
            + $summary->total_charges
            + $summary->total_penalty;

        return [
            'total_principal' => (float) $summary->total_principal,
            'total_interest' => (float) $summary->total_interest,
            'total_charges' => (float) $summary->total_charges,
            'total_penalty' => (float) $summary->total_penalty,
            'grand_total' => (float) $grandTotal,
            'loan_count' => (int) $summary->loan_count,
        ];
    }

    protected function mapStatus(string $status): string
    {
        return match ($status) {
            'disbursed', 'running', 'active' => 'disbursed',
            'closed', 'fully_repaid' => 'closed',
            'written_off', 'defaulted' => 'written_off',
            default => $status,
        };
    }

    protected function mapStatusFilter(string $filter): string|array
    {
        return match ($filter) {
            'active' => ['disbursed', 'active', 'running'],
            'arrears' => ['arrears', 'defaulted'],
            'closed' => ['closed', 'fully_repaid', 'written_off'],
            default => $filter,
        };
    }

    protected function applyBranchScope($query): void
    {
        $user = auth()->user();

        if (! $user instanceof Staff) {
            return;
        }

        $scope = BranchContext::scopeFor($user);

        // For SCOPE_ALL: honour the branch_id filter already applied via request filters — no extra restriction.
        // For SCOPE_BRANCH / SCOPE_SELF: restrict to the user's own branch, consistent with the
        // active loans page (BelongsToAuthenticatedBranch) and the aging report (effectiveBranchId).
        if ($scope !== BranchContext::SCOPE_ALL && $user->branch_id) {
            $query->where('l.branch_id', $user->branch_id);
        }
    }

    public function getExportData(array $filters): array
    {
        $asOf = $filters['as_of_date'] ?? now()->toDateString();

        $baseQuery = $this->baseQuery($asOf, $filters);

        $loans = $baseQuery
            ->selectRaw('l.id as loan_id')
            ->selectRaw('l.loan_no')
            ->selectRaw('l.status')
            ->selectRaw('l.disbursed_at')
            ->selectRaw('m.id as member_id')
            ->selectRaw('m.name as member_name')
            ->selectRaw('m.member_number')
            ->selectRaw('COALESCE(p.name, \'\') as product_name')
            ->selectRaw('COALESCE(b.name, \'Unassigned\') as branch_name')
            ->selectRaw('COALESCE(s.name, \'—\') as loan_officer_name')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.principal_due - rs.principal_paid)), 0) as principal_remaining')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.interest_due - rs.interest_paid)), 0) as interest_remaining')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.charges_due - rs.charges_paid)), 0) as charges_remaining')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.penalty_due - rs.penalty_paid)), 0) as penalty_remaining')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due) - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid)), 0) as total_balance')
            ->selectRaw('MAX(CASE WHEN rs.due_date < ? AND rs.status != \'paid\' THEN DATEDIFF(?, rs.due_date) ELSE 0 END) as days_in_arrears', [$asOf, $asOf])
            ->selectRaw('MIN(CASE WHEN rs.status != \'paid\' AND rs.due_date >= ? THEN rs.due_date END) as next_due_date', [$asOf])
            ->groupBy('l.id', 'l.loan_no', 'l.status', 'l.disbursed_at',
                'm.id', 'm.name', 'm.member_number', 'p.name', 'b.name', 's.name')
            ->orderByDesc('total_balance')
            ->get();

        return $loans->map(fn ($loan) => [
            'loan_id' => $loan->loan_id,
            'loan_no' => $loan->loan_no,
            'member_id' => $loan->member_id,
            'member_name' => $loan->member_name,
            'member_number' => $loan->member_number,
            'branch_name' => $loan->branch_name,
            'loan_officer_name' => $loan->loan_officer_name,
            'product_name' => $loan->product_name,
            'principal' => $loan->principal_remaining,
            'interest_remaining' => $loan->interest_remaining,
            'charges_remaining' => $loan->charges_remaining,
            'penalty_remaining' => $loan->penalty_remaining,
            'outstanding_balance' => $loan->total_balance,
            'status' => $this->mapStatus($loan->status),
            'disbursed_at' => $loan->disbursed_at,
            'next_due_date' => $loan->next_due_date,
            'days_in_arrears' => $loan->days_in_arrears > 0 ? (int) $loan->days_in_arrears : null,
        ])->toArray();
    }
}
