<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Staff;
use App\Support\BranchContext;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanDisbursementReportService
{
    /**
     * Summary KPIs + breakdowns by product, channel, branch, officer.
     */
    public function getSummary(array $filters): array
    {
        $filters = $this->normalizedFilters($filters);

        $kpis = $this->buildKpis($filters);
        $byProduct = $this->breakdownByProduct($filters);
        $byChannel = $this->breakdownByChannel($filters);
        $byBranch = $this->breakdownByBranch($filters);
        $byOfficer = $this->breakdownByOfficer($filters);
        $pending = $this->pendingDisbursements($filters);

        return [
            'kpis' => $kpis,
            'pending' => $pending,
            'by_product' => $byProduct,
            'by_channel' => $byChannel,
            'by_branch' => $byBranch,
            'by_officer' => $byOfficer,
        ];
    }

    /**
     * Paginated loan-level transaction register.
     */
    public function getReport(array $filters): array
    {
        $filters = $this->normalizedFilters($filters);
        $perPage = max(1, min(2000, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $baseQuery = $this->registerBaseQuery($filters);
        $total = (clone $baseQuery)->count();

        $rows = (clone $baseQuery)
            ->orderByDesc('l.disbursed_at')
            ->orderBy('l.id')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $data = $rows->map(fn ($row) => $this->mapLoanRow($row))->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => (int) ceil(max($total, 1) / $perPage),
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total > 0 ? ($offset + 1) : null,
                'to' => $total > 0 ? min($offset + $perPage, $total) : null,
            ],
        ];
    }

    /**
     * Monthly trend for the last N months (default 3).
     */
    public function getTrend(array $filters): array
    {
        $filters = $this->normalizedFilters($filters);
        $months = max(1, min(12, (int) ($filters['months'] ?? 3)));

        $dateTo = Carbon::parse($filters['date_to']);
        $dateFrom = $dateTo->copy()->startOfMonth()->subMonths($months - 1);

        $query = DB::connection('tenant')
            ->table('loans as l')
            ->whereNull('l.deleted_at')
            ->whereNotNull('l.disbursed_at')
            ->whereDate('l.disbursed_at', '>=', $dateFrom->toDateString())
            ->whereDate('l.disbursed_at', '<=', $dateTo->toDateString());

        $this->applyLoanFilters($query, $filters);

        $rows = $query
            ->selectRaw("DATE_FORMAT(l.disbursed_at, '%Y-%m') as month")
            ->selectRaw('COUNT(*) as loan_count')
            ->selectRaw('COALESCE(SUM(l.principal), 0) as total_disbursed')
            ->groupByRaw("DATE_FORMAT(l.disbursed_at, '%Y-%m')")
            ->get()
            ->keyBy('month');

        $trend = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = $dateTo->copy()->startOfMonth()->subMonths($i);
            $monthKey = $m->format('Y-m');

            if ($rows->has($monthKey)) {
                $row = $rows->get($monthKey);
                $trend[] = [
                    'month' => $monthKey,
                    'label' => $m->format('M Y'),
                    'loan_count' => (int) $row->loan_count,
                    'total_disbursed' => (float) $row->total_disbursed,
                ];
            } else {
                $trend[] = [
                    'month' => $monthKey,
                    'label' => $m->format('M Y'),
                    'loan_count' => 0,
                    'total_disbursed' => 0.0,
                ];
            }
        }

        return $trend;
    }

    /**
     * Non-paginated data for the Excel export.
     */
    public function getExportData(array $filters): array
    {
        $filters = $this->normalizedFilters($filters);

        $summaryData = $this->getSummary($filters);

        $loanRows = $this->registerBaseQuery($filters)
            ->orderByDesc('l.disbursed_at')
            ->orderBy('l.id')
            ->get()
            ->map(fn ($row) => $this->mapLoanRow($row))
            ->values()
            ->all();

        return [
            'summary' => $summaryData,
            'loans' => $loanRows,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function buildKpis(array $filters): array
    {
        $query = $this->disbursedLoansBaseQuery($filters);

        $result = (clone $query)
            ->selectRaw('COUNT(*) as loan_count')
            ->selectRaw('COALESCE(SUM(l.principal), 0) as total_disbursed')
            ->selectRaw('COALESCE(AVG(l.principal), 0) as avg_loan_size')
            ->first();

        return [
            'total_disbursed' => (float) ($result->total_disbursed ?? 0),
            'loan_count' => (int) ($result->loan_count ?? 0),
            'avg_loan_size' => round((float) ($result->avg_loan_size ?? 0), 2),
        ];
    }

    private function pendingDisbursements(array $filters): array
    {
        $query = DB::connection('tenant')
            ->table('loan_applications as la')
            ->whereNull('la.deleted_at')
            ->where('la.status', 'disbursement_pending');

        if (! empty($filters['branch_id'])) {
            $query->where('la.branch_id', (int) $filters['branch_id']);
        }

        if (! empty($filters['loan_officer_id'])) {
            $query->where('la.loan_officer_id', (int) $filters['loan_officer_id']);
        }

        $result = $query
            ->selectRaw('COUNT(*) as pending_count')
            ->selectRaw('COALESCE(SUM(COALESCE(la.final_approved_amount, la.approved_amount, la.requested_amount)), 0) as pending_amount')
            ->first();

        return [
            'pending_count' => (int) ($result->pending_count ?? 0),
            'pending_amount' => (float) ($result->pending_amount ?? 0),
        ];
    }

    private function breakdownByProduct(array $filters): array
    {
        $query = $this->disbursedLoansBaseQuery($filters);

        return $this->mapBreakdownRows(
            (clone $query)
                ->leftJoin('loan_products as p', 'p.id', '=', 'l.loan_product_id')
                ->selectRaw("COALESCE(p.name, 'Unknown') as name")
                ->selectRaw('COUNT(*) as loan_count')
                ->selectRaw('COALESCE(SUM(l.principal), 0) as total_amount')
                ->groupBy('p.name')
                ->orderByDesc('total_amount')
                ->get(),
            (float) ((clone $query)->sum('l.principal') ?: 0)
        );
    }

    private function breakdownByChannel(array $filters): array
    {
        $query = $this->disbursedLoansBaseQuery($filters);

        return $this->mapBreakdownRows(
            (clone $query)
                ->selectRaw("COALESCE(NULLIF(l.disbursement_method, ''), 'Unknown') as name")
                ->selectRaw('COUNT(*) as loan_count')
                ->selectRaw('COALESCE(SUM(l.principal), 0) as total_amount')
                ->groupByRaw("COALESCE(NULLIF(l.disbursement_method, ''), 'Unknown')")
                ->orderByDesc('total_amount')
                ->get(),
            (float) ((clone $query)->sum('l.principal') ?: 0)
        );
    }

    private function breakdownByBranch(array $filters): array
    {
        $query = $this->disbursedLoansBaseQuery($filters);

        return $this->mapBreakdownRows(
            (clone $query)
                ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
                ->selectRaw("COALESCE(b.name, 'Unassigned') as name")
                ->selectRaw('COUNT(*) as loan_count')
                ->selectRaw('COALESCE(SUM(l.principal), 0) as total_amount')
                ->groupBy('b.name')
                ->orderByDesc('total_amount')
                ->get(),
            (float) ((clone $query)->sum('l.principal') ?: 0)
        );
    }

    private function breakdownByOfficer(array $filters): array
    {
        $query = $this->disbursedLoansBaseQuery($filters);

        return $this->mapBreakdownRows(
            (clone $query)
                ->leftJoin('staff as s', 's.id', '=', 'l.loan_officer_id')
                ->selectRaw("COALESCE(s.name, 'Unassigned') as name")
                ->selectRaw('COUNT(*) as loan_count')
                ->selectRaw('COALESCE(SUM(l.principal), 0) as total_amount')
                ->groupBy('s.name')
                ->orderByDesc('total_amount')
                ->get(),
            (float) ((clone $query)->sum('l.principal') ?: 0)
        );
    }

    private function mapBreakdownRows($rows, float $grandTotal): array
    {
        return $rows->map(fn ($row) => [
            'name' => $row->name,
            'loan_count' => (int) $row->loan_count,
            'total_amount' => (float) $row->total_amount,
            'percentage' => $grandTotal > 0
                ? round(((float) $row->total_amount / $grandTotal) * 100, 1)
                : 0,
        ])->values()->all();
    }

    private function disbursedLoansBaseQuery(array $filters)
    {
        $query = DB::connection('tenant')
            ->table('loans as l')
            ->whereNull('l.deleted_at')
            ->whereNotNull('l.disbursed_at')
            ->whereNotIn('l.status', [
                LoanStatus::Closed->value,
                LoanStatus::WrittenOff->value,
                LoanStatus::Arrears->value,
            ])
            ->where('l.is_rescheduled', 0)
            ->whereDate('l.disbursed_at', '>=', $filters['date_from'])
            ->whereDate('l.disbursed_at', '<=', $filters['date_to']);

        $this->applyLoanFilters($query, $filters);

        return $query;
    }

    private function registerBaseQuery(array $filters)
    {
        $repaidSub = DB::connection('tenant')
            ->table('loan_transactions as lt')
            ->where('lt.reversal_flag', 0)
            ->whereNotNull('lt.payment_date')
            ->selectRaw('lt.loan_id')
            ->selectRaw('COALESCE(SUM(lt.amount_paid), 0) as total_repaid')
            ->groupBy('lt.loan_id');

        $query = $this->disbursedLoansBaseQuery($filters)
            ->join('members as m', 'm.id', '=', 'l.member_id')
            ->leftJoin('loan_products as p', 'p.id', '=', 'l.loan_product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->leftJoin('staff as s', 's.id', '=', 'l.loan_officer_id')
            ->leftJoinSub($repaidSub, 'repaid', fn ($join) => $join->on('repaid.loan_id', '=', 'l.id'))
            ->whereNull('m.deleted_at')
            ->select([
                'l.id as loan_id',
                'l.loan_no',
                'l.principal',
                'l.net_disbursed_amount',
                'l.interest_rate',
                'l.term_months',
                'l.disbursement_method',
                'l.disbursed_at',
                'l.status',
                'l.outstanding_balance',
                'm.name as member_name',
                'm.member_number',
                DB::raw("COALESCE(p.name, '') as product_name"),
                DB::raw("COALESCE(b.name, 'Unassigned') as branch_name"),
                DB::raw("COALESCE(s.name, 'Unassigned') as loan_officer_name"),
                DB::raw('COALESCE(repaid.total_repaid, 0) as total_repaid'),
            ]);

        return $query;
    }

    private function mapLoanRow(object $row): array
    {
        $principal = (float) ($row->principal ?? 0);
        $totalRepaid = (float) ($row->total_repaid ?? 0);
        $repaidPercent = $principal > 0
            ? round(($totalRepaid / $principal) * 100, 1)
            : 0;

        return [
            'loan_id' => (int) $row->loan_id,
            'loan_no' => $row->loan_no,
            'member_name' => $row->member_name,
            'member_number' => $row->member_number,
            'product_name' => $row->product_name,
            'principal' => $principal,
            'net_disbursed_amount' => (float) ($row->net_disbursed_amount ?? 0),
            'interest_rate' => (float) ($row->interest_rate ?? 0),
            'term_months' => (int) ($row->term_months ?? 0),
            'disbursement_method' => $row->disbursement_method ?? 'Unknown',
            'disbursed_at' => $row->disbursed_at,
            'status' => $row->status ?? '',
            'outstanding_balance' => (float) ($row->outstanding_balance ?? 0),
            'total_repaid' => $totalRepaid,
            'repaid_percent' => $repaidPercent,
            'branch_name' => $row->branch_name,
            'loan_officer_name' => $row->loan_officer_name,
        ];
    }

    private function applyLoanFilters($query, array $filters): void
    {
        if (! empty($filters['branch_id'])) {
            $query->where('l.branch_id', (int) $filters['branch_id']);
        }

        if (! empty($filters['loan_officer_id'])) {
            $query->where('l.loan_officer_id', (int) $filters['loan_officer_id']);
        }

        if (! empty($filters['loan_product_id'])) {
            $query->where('l.loan_product_id', (int) $filters['loan_product_id']);
        }

        $this->applyBranchScope($query);
    }

    private function applyBranchScope($query): void
    {
        $user = auth()->user();

        if (! $user instanceof Staff) {
            return;
        }

        $scope = BranchContext::scopeFor($user);

        if ($scope !== BranchContext::SCOPE_ALL) {
            $allowedIds = BranchContext::allowedBranchIds();
            if (! empty($allowedIds)) {
                $query->whereIn('l.branch_id', $allowedIds);
            }
        }
    }

    private function normalizedFilters(array $filters): array
    {
        $normalized = array_merge([
            'date_from' => null,
            'date_to' => null,
            'branch_id' => null,
            'loan_officer_id' => null,
            'loan_product_id' => null,
            'per_page' => 25,
            'page' => 1,
            'months' => 3,
        ], $filters);

        if (empty($normalized['date_from'])) {
            $normalized['date_from'] = now()->startOfMonth()->toDateString();
        }

        if (empty($normalized['date_to'])) {
            $normalized['date_to'] = now()->toDateString();
        }

        return $normalized;
    }
}
