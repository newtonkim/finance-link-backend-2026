<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Staff;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;

class LoanArrearsReportService
{
    /**
     * Paginated list of loans currently in arrears status.
     * One row per loan with total overdue amounts and collections metadata.
     */
    public function getReport(array $filters): array
    {
        $asOf = $filters['as_of_date'] ?? now()->toDateString();
        $perPage = max(1, (int) ($filters['per_page'] ?? 25));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $base = $this->baseQuery($asOf, $filters);

        $countQuery = clone $base;

        $rows = $base
            ->selectRaw('l.id                                                           as loan_id')
            ->selectRaw('l.loan_no')
            ->selectRaw('l.disbursed_at')
            ->selectRaw('m.id                                                           as member_id')
            ->selectRaw('m.name                                                         as member_name')
            ->selectRaw('m.member_number')
            ->selectRaw("COALESCE(m.phone, '')                                          as phone")
            ->selectRaw("COALESCE(b.name, 'Unassigned')                                as branch_name")
            ->selectRaw("COALESCE(s.name, '—')                                         as loan_officer_name")
            ->selectRaw("COALESCE(p.name, '')                                           as product_name")
            ->selectRaw('
                COALESCE(SUM(GREATEST(0,
                    (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                    - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid)
                )), 0) as total_arrears
            ')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.principal_due - rs.principal_paid)), 0) as principal_arrears')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.interest_due  - rs.interest_paid)),  0) as interest_arrears')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.charges_due   - rs.charges_paid)),   0) as charges_arrears')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.penalty_due   - rs.penalty_paid)),   0) as penalty_arrears')
            ->selectRaw('COUNT(DISTINCT rs.id)                                          as missed_installments')
            ->selectRaw('MAX(DATEDIFF(?, rs.due_date))                                  as days_in_arrears', [$asOf])
            ->selectRaw('MAX(lt_last.payment_date)                                      as last_payment_date')
            ->groupBy(
                'l.id', 'l.loan_no', 'l.disbursed_at',
                'm.id', 'm.name', 'm.member_number', 'm.phone',
                'b.name', 's.name', 'p.name',
            )
            ->orderByDesc('days_in_arrears')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $total = $countQuery->distinct()->count('l.id');

        return [
            'loans' => [
                'data' => $rows->map(fn ($r) => [
                    'loan_id' => $r->loan_id,
                    'loan_no' => $r->loan_no,
                    'member_id' => $r->member_id,
                    'member_name' => $r->member_name,
                    'member_number' => $r->member_number,
                    'phone' => $r->phone,
                    'branch_name' => $r->branch_name,
                    'loan_officer_name' => $r->loan_officer_name,
                    'product_name' => $r->product_name,
                    'total_arrears' => (float) $r->total_arrears,
                    'principal_arrears' => (float) $r->principal_arrears,
                    'interest_arrears' => (float) $r->interest_arrears,
                    'charges_arrears' => (float) $r->charges_arrears,
                    'penalty_arrears' => (float) $r->penalty_arrears,
                    'missed_installments' => (int) $r->missed_installments,
                    'days_in_arrears' => (int) $r->days_in_arrears,
                    'last_payment_date' => $r->last_payment_date,
                    'disbursed_at' => $r->disbursed_at,
                ]),
                'meta' => [
                    'current_page' => $page,
                    'last_page' => (int) ceil($total / $perPage),
                    'per_page' => $perPage,
                    'total' => $total,
                    'from' => $offset + 1,
                    'to' => min($offset + $perPage, $total),
                ],
            ],
        ];
    }

    /**
     * Historical paginated list — mirrors getReport() but uses paid_date to
     * determine what was owed at a past as_of_date.
     * Amounts use full due amounts for installments paid after the snapshot date,
     * so per-loan totals sum to exactly what historicalSnapshotAt() returns.
     */
    public function getHistoricalReport(array $filters): array
    {
        $asOf = $filters['as_of_date'] ?? now()->toDateString();
        $perPage = max(1, (int) ($filters['per_page'] ?? 25));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $lastPayment = DB::connection('tenant')
            ->table('loan_transactions as lt')
            ->where('lt.reversal_flag', 0)
            ->where('lt.payment_date', '<=', $asOf)
            ->selectRaw('lt.loan_id')
            ->selectRaw('MAX(lt.payment_date) as payment_date')
            ->groupBy('lt.loan_id');

        $base = DB::connection('tenant')
            ->table('loans as l')
            ->join('loan_repayment_schedule as rs', 'rs.loan_id', '=', 'l.id')
            ->join('members as m', 'm.id', '=', 'l.member_id')
            ->leftJoin('loan_products as p', 'p.id', '=', 'l.loan_product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->leftJoin('staff as s', 's.id', '=', 'l.loan_officer_id')
            ->leftJoinSub($lastPayment, 'lt_last',
                fn ($join) => $join->on('lt_last.loan_id', '=', 'l.id')
            )
            ->whereNull('l.deleted_at')
            ->whereNull('m.deleted_at')
            ->whereDate('l.disbursed_at', '<=', $asOf)
            ->where('rs.due_date', '<=', $asOf)
            ->where(function ($q) use ($asOf) {
                $q->whereNull('rs.paid_date')
                    ->orWhere('rs.paid_date', '>', $asOf);
            });

        if (! empty($filters['branch_id'])) {
            $base->where('l.branch_id', $filters['branch_id']);
        }

        if (! empty($filters['loan_officer_id'])) {
            $base->where('l.loan_officer_id', $filters['loan_officer_id']);
        }

        $this->applyBranchScope($base);

        $countQuery = clone $base;

        $rows = $base
            ->selectRaw('l.id                                                              as loan_id')
            ->selectRaw('l.loan_no')
            ->selectRaw('l.disbursed_at')
            ->selectRaw('m.id                                                              as member_id')
            ->selectRaw('m.name                                                            as member_name')
            ->selectRaw('m.member_number')
            ->selectRaw("COALESCE(m.phone, '')                                             as phone")
            ->selectRaw("COALESCE(b.name, 'Unassigned')                                   as branch_name")
            ->selectRaw("COALESCE(s.name, '—')                                            as loan_officer_name")
            ->selectRaw("COALESCE(p.name, '')                                              as product_name")
            ->selectRaw('
                COALESCE(SUM(
                    CASE
                        WHEN rs.paid_date IS NULL THEN
                            GREATEST(0,
                                (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                                - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid)
                            )
                        ELSE
                            (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                    END
                ), 0) as total_arrears
            ')
            ->selectRaw('
                COALESCE(SUM(
                    CASE WHEN rs.paid_date IS NULL
                        THEN GREATEST(0, rs.principal_due - rs.principal_paid)
                        ELSE rs.principal_due END
                ), 0) as principal_arrears
            ')
            ->selectRaw('
                COALESCE(SUM(
                    CASE WHEN rs.paid_date IS NULL
                        THEN GREATEST(0, rs.interest_due - rs.interest_paid)
                        ELSE rs.interest_due END
                ), 0) as interest_arrears
            ')
            ->selectRaw('
                COALESCE(SUM(
                    CASE WHEN rs.paid_date IS NULL
                        THEN GREATEST(0, rs.charges_due - rs.charges_paid)
                        ELSE rs.charges_due END
                ), 0) as charges_arrears
            ')
            ->selectRaw('
                COALESCE(SUM(
                    CASE WHEN rs.paid_date IS NULL
                        THEN GREATEST(0, rs.penalty_due - rs.penalty_paid)
                        ELSE rs.penalty_due END
                ), 0) as penalty_arrears
            ')
            ->selectRaw('COUNT(DISTINCT rs.id)                                             as missed_installments')
            ->selectRaw('MAX(DATEDIFF(?, rs.due_date))                                     as days_in_arrears', [$asOf])
            ->selectRaw('MAX(lt_last.payment_date)                                         as last_payment_date')
            ->groupBy(
                'l.id', 'l.loan_no', 'l.disbursed_at',
                'm.id', 'm.name', 'm.member_number', 'm.phone',
                'b.name', 's.name', 'p.name',
            )
            ->orderByDesc('days_in_arrears')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $total = $countQuery->distinct()->count('l.id');

        return [
            'loans' => [
                'data' => $rows->map(fn ($r) => [
                    'loan_id' => $r->loan_id,
                    'loan_no' => $r->loan_no,
                    'member_id' => $r->member_id,
                    'member_name' => $r->member_name,
                    'member_number' => $r->member_number,
                    'phone' => $r->phone,
                    'branch_name' => $r->branch_name,
                    'loan_officer_name' => $r->loan_officer_name,
                    'product_name' => $r->product_name,
                    'total_arrears' => (float) $r->total_arrears,
                    'principal_arrears' => (float) $r->principal_arrears,
                    'interest_arrears' => (float) $r->interest_arrears,
                    'charges_arrears' => (float) $r->charges_arrears,
                    'penalty_arrears' => (float) $r->penalty_arrears,
                    'missed_installments' => (int) $r->missed_installments,
                    'days_in_arrears' => (int) $r->days_in_arrears,
                    'last_payment_date' => $r->last_payment_date,
                    'disbursed_at' => $r->disbursed_at,
                ]),
                'meta' => [
                    'current_page' => $page,
                    'last_page' => (int) ceil($total / $perPage),
                    'per_page' => $perPage,
                    'total' => $total,
                    'from' => $offset + 1,
                    'to' => min($offset + $perPage, $total),
                ],
            ],
        ];
    }

    /**
     * Three-point comparison: today, −30 days, −90 days.
     * "Today" uses status = 'arrears' to match the loans table exactly.
     * Historical points use paid_date-based detection for genuine accuracy.
     */
    public function getComparison(array $filters): array
    {
        $asOf = $filters['as_of_date'] ?? now()->toDateString();

        $today = $asOf;
        $oneMonthAgo = now()->parse($asOf)->subDays(30)->toDateString();
        $threeMonthsAgo = now()->parse($asOf)->subDays(90)->toDateString();

        return [
            'today' => $this->snapshotAt($today, $filters),
            'one_month_ago' => $this->historicalSnapshotAt($oneMonthAgo, $filters),
            'three_months_ago' => $this->historicalSnapshotAt($threeMonthsAgo, $filters),
        ];
    }

    /**
     * Monthly arrears totals for the last N months (3, 6, or 12).
     * Uses paid_date on the schedule for genuine historical accuracy:
     * an installment counts as overdue at a month-end only if it was not yet
     * paid by that date (paid_date IS NULL or paid_date > month_end).
     */
    public function getTrend(array $filters): array
    {
        $asOf = $filters['as_of_date'] ?? now()->toDateString();
        $months = in_array((int) ($filters['months'] ?? 12), [3, 6, 12]) ? (int) $filters['months'] : 12;

        $points = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $monthEnd = now()->parse($asOf)->subMonths($i)->endOfMonth()->toDateString();

            if ($monthEnd > $asOf) {
                $monthEnd = $asOf;
            }

            $snapshot = $this->historicalSnapshotAt($monthEnd, $filters);

            $points[] = [
                'month' => now()->parse($monthEnd)->format('Y-m'),
                'label' => now()->parse($monthEnd)->format('M Y'),
                'month_end' => $monthEnd,
                'loan_count' => $snapshot['loan_count'],
                'total_arrears' => $snapshot['total_arrears'],
            ];
        }

        return $points;
    }

    /**
     * All overdue installments for a single loan — used for the expandable row.
     * Returns only installments with a non-zero total shortfall.
     */
    public function getInstallmentDetail(int $loanId, string $asOfDate): array
    {
        $rows = DB::connection('tenant')
            ->table('loan_repayment_schedule as rs')
            ->where('rs.loan_id', $loanId)
            ->where('rs.status', '!=', 'paid')
            ->where('rs.due_date', '<=', $asOfDate)
            ->select([
                'rs.installment_no',
                'rs.due_date',
                DB::raw('GREATEST(0, rs.principal_due - rs.principal_paid) as principal_shortfall'),
                DB::raw('GREATEST(0, rs.interest_due  - rs.interest_paid)  as interest_shortfall'),
                DB::raw('GREATEST(0, rs.charges_due   - rs.charges_paid)   as charges_shortfall'),
                DB::raw('GREATEST(0, rs.penalty_due   - rs.penalty_paid)   as penalty_shortfall'),
                DB::raw('
                    GREATEST(0,
                        (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                        - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid)
                    ) as total_shortfall
                '),
            ])
            ->orderBy('rs.due_date')
            ->get();

        return $rows
            ->filter(fn ($r) => (float) $r->total_shortfall > 0)
            ->map(fn ($r) => [
                'installment_no' => $r->installment_no,
                'due_date' => $r->due_date,
                'principal_shortfall' => (float) $r->principal_shortfall,
                'interest_shortfall' => (float) $r->interest_shortfall,
                'charges_shortfall' => (float) $r->charges_shortfall,
                'penalty_shortfall' => (float) $r->penalty_shortfall,
                'total_shortfall' => (float) $r->total_shortfall,
            ])
            ->values()
            ->all();
    }

    /**
     * Full unpaginated loans list for Excel export.
     */
    public function getExportData(array $filters): array
    {
        $asOf = $filters['as_of_date'] ?? now()->toDateString();
        $base = $this->baseQuery($asOf, $filters);

        $rows = $base
            ->selectRaw('l.id                                                           as loan_id')
            ->selectRaw('l.loan_no')
            ->selectRaw('l.disbursed_at')
            ->selectRaw('m.id                                                           as member_id')
            ->selectRaw('m.name                                                         as member_name')
            ->selectRaw('m.member_number')
            ->selectRaw("COALESCE(m.phone, '')                                          as phone")
            ->selectRaw("COALESCE(b.name, 'Unassigned')                                as branch_name")
            ->selectRaw("COALESCE(s.name, '—')                                         as loan_officer_name")
            ->selectRaw("COALESCE(p.name, '')                                           as product_name")
            ->selectRaw('
                COALESCE(SUM(GREATEST(0,
                    (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                    - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid)
                )), 0) as total_arrears
            ')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.principal_due - rs.principal_paid)), 0) as principal_arrears')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.interest_due  - rs.interest_paid)),  0) as interest_arrears')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.charges_due   - rs.charges_paid)),   0) as charges_arrears')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.penalty_due   - rs.penalty_paid)),   0) as penalty_arrears')
            ->selectRaw('COUNT(DISTINCT rs.id)                                          as missed_installments')
            ->selectRaw('MAX(DATEDIFF(?, rs.due_date))                                  as days_in_arrears', [$asOf])
            ->selectRaw('MAX(lt_last.payment_date)                                      as last_payment_date')
            ->groupBy(
                'l.id', 'l.loan_no', 'l.disbursed_at',
                'm.id', 'm.name', 'm.member_number', 'm.phone',
                'b.name', 's.name', 'p.name',
            )
            ->orderByDesc('days_in_arrears')
            ->get();

        return $rows->map(fn ($r) => [
            'loan_id' => $r->loan_id,
            'loan_no' => $r->loan_no,
            'member_name' => $r->member_name,
            'member_number' => $r->member_number,
            'phone' => $r->phone,
            'branch_name' => $r->branch_name,
            'loan_officer_name' => $r->loan_officer_name,
            'product_name' => $r->product_name,
            'total_arrears' => (float) $r->total_arrears,
            'principal_arrears' => (float) $r->principal_arrears,
            'interest_arrears' => (float) $r->interest_arrears,
            'charges_arrears' => (float) $r->charges_arrears,
            'penalty_arrears' => (float) $r->penalty_arrears,
            'missed_installments' => (int) $r->missed_installments,
            'days_in_arrears' => (int) $r->days_in_arrears,
            'last_payment_date' => $r->last_payment_date,
            'disbursed_at' => $r->disbursed_at,
        ])->all();
    }

    // ─── Snapshot helpers ─────────────────────────────────────────────────────

    /**
     * Genuine historical snapshot using paid_date on the schedule.
     *
     * An installment was "overdue and unpaid" at $date when:
     *   - due_date <= $date           (it was already due)
     *   - paid_date IS NULL           → still unpaid today  → full current shortfall
     *   - paid_date >  $date          → paid after snapshot → full due amount was owed
     *
     * No l.status filter — captures any loan that had overdue installments at that
     * historical point, regardless of what its status is today.
     */
    protected function historicalSnapshotAt(string $date, array $filters): array
    {
        $query = DB::connection('tenant')
            ->table('loans as l')
            ->join('loan_repayment_schedule as rs', 'rs.loan_id', '=', 'l.id')
            ->whereNull('l.deleted_at')
            ->whereDate('l.disbursed_at', '<=', $date)
            ->where('rs.due_date', '<=', $date)
            ->where(function ($q) use ($date) {
                // Installment was not yet fully paid by $date
                $q->whereNull('rs.paid_date')
                    ->orWhere('rs.paid_date', '>', $date);
            });

        if (! empty($filters['branch_id'])) {
            $query->where('l.branch_id', $filters['branch_id']);
        }

        if (! empty($filters['loan_officer_id'])) {
            $query->where('l.loan_officer_id', $filters['loan_officer_id']);
        }

        $this->applyBranchScope($query);

        $result = $query
            ->selectRaw('COUNT(DISTINCT l.id) as loan_count')
            ->selectRaw('
                COALESCE(SUM(
                    CASE
                        WHEN rs.paid_date IS NULL THEN
                            GREATEST(0,
                                (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                                - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid)
                            )
                        ELSE
                            (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                    END
                ), 0) as total_arrears
            ')
            ->selectRaw('
                COALESCE(SUM(
                    CASE
                        WHEN rs.paid_date IS NULL THEN
                            GREATEST(0, rs.principal_due - rs.principal_paid)
                        ELSE
                            rs.principal_due
                    END
                ), 0) as principal_arrears
            ')
            ->first();

        return [
            'date' => $date,
            'loan_count' => (int) ($result->loan_count ?? 0),
            'total_arrears' => (float) ($result->total_arrears ?? 0),
            'principal_arrears' => (float) ($result->principal_arrears ?? 0),
        ];
    }

    /**
     * Snapshot using current l.status = 'arrears' — used only for the "Today" card
     * so it matches the loans table count exactly.
     */
    protected function snapshotAt(string $date, array $filters): array
    {
        $lastPayment = DB::connection('tenant')
            ->table('loan_transactions as lt')
            ->where('lt.reversal_flag', 0)
            ->where('lt.payment_date', '<=', $date)
            ->selectRaw('lt.loan_id')
            ->selectRaw('MAX(lt.payment_date) as payment_date')
            ->groupBy('lt.loan_id');

        $query = DB::connection('tenant')
            ->table('loans as l')
            ->join('loan_repayment_schedule as rs', 'rs.loan_id', '=', 'l.id')
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->leftJoinSub($lastPayment, 'lt_last',
                fn ($join) => $join->on('lt_last.loan_id', '=', 'l.id')
            )
            ->whereNull('l.deleted_at')
            ->where('l.status', 'arrears')
            ->where('rs.status', '!=', 'paid')
            ->where('rs.due_date', '<=', $date);

        if (! empty($filters['branch_id'])) {
            $query->where('l.branch_id', $filters['branch_id']);
        }

        if (! empty($filters['loan_officer_id'])) {
            $query->where('l.loan_officer_id', $filters['loan_officer_id']);
        }

        $this->applyBranchScope($query);

        $result = $query
            ->selectRaw('COUNT(DISTINCT l.id) as loan_count')
            ->selectRaw('
                COALESCE(SUM(GREATEST(0,
                    (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                    - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid)
                )), 0) as total_arrears
            ')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.principal_due - rs.principal_paid)), 0) as principal_arrears')
            ->first();

        return [
            'date' => $date,
            'loan_count' => (int) ($result->loan_count ?? 0),
            'total_arrears' => (float) ($result->total_arrears ?? 0),
            'principal_arrears' => (float) ($result->principal_arrears ?? 0),
        ];
    }

    // ─── Base query ───────────────────────────────────────────────────────────

    /**
     * Shared FROM + JOIN + WHERE for the main loans table.
     * Only loans with status = 'arrears'.
     * Only schedule rows that are overdue (due_date <= asOf, status != 'paid').
     */
    protected function baseQuery(string $asOf, array $filters)
    {
        $lastPayment = DB::connection('tenant')
            ->table('loan_transactions as lt')
            ->where('lt.reversal_flag', 0)
            ->selectRaw('lt.loan_id')
            ->selectRaw('MAX(lt.payment_date) as payment_date')
            ->groupBy('lt.loan_id');

        $query = DB::connection('tenant')
            ->table('loans as l')
            ->join('loan_repayment_schedule as rs', 'rs.loan_id', '=', 'l.id')
            ->join('members as m', 'm.id', '=', 'l.member_id')
            ->leftJoin('loan_products as p', 'p.id', '=', 'l.loan_product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->leftJoin('staff as s', 's.id', '=', 'l.loan_officer_id')
            ->leftJoinSub($lastPayment, 'lt_last',
                fn ($join) => $join->on('lt_last.loan_id', '=', 'l.id')
            )
            ->whereNull('l.deleted_at')
            ->whereNull('m.deleted_at')
            ->where('l.status', 'arrears')
            ->where('rs.status', '!=', 'paid')
            ->where('rs.due_date', '<=', $asOf);

        if (! empty($filters['branch_id'])) {
            $query->where('l.branch_id', $filters['branch_id']);
        }

        if (! empty($filters['loan_officer_id'])) {
            $query->where('l.loan_officer_id', $filters['loan_officer_id']);
        }

        $this->applyBranchScope($query);

        return $query;
    }

    // ─── Branch scope ─────────────────────────────────────────────────────────

    protected function applyBranchScope($query): void
    {
        $user = auth()->user();

        if (! $user instanceof Staff) {
            return;
        }

        $scope = BranchContext::scopeFor($user);

        if ($scope !== BranchContext::SCOPE_ALL && $user->branch_id) {
            $query->where('l.branch_id', $user->branch_id);
        }
    }
}
