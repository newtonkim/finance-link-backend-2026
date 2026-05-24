<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Staff;
use App\Support\BranchContext;
use App\Tenant\Modules\Loans\Contracts\LoanAgingReportServiceInterface;
use Illuminate\Support\Facades\DB;

class LoanAgingReportService implements LoanAgingReportServiceInterface
{
    /**
     * Standard SACCO provisioning rate per aging bucket (%).
     * Source: Central Bank / SASRA prudential guidelines.
     * "Current" bucket removed — the base query only includes overdue installments
     * (due_date < as_of_date), so days_past_due is always >= 1.
     */
    private const PROVISION_RATES = [
        '1-30' => 5.0,
        '31-60' => 25.0,
        '61-90' => 50.0,
        '91-180' => 75.0,
        '180+' => 100.0,
    ];

    /**
     * Canonical bucket order for consistent display.
     */
    private const BUCKET_ORDER = ['1-30', '31-60', '61-90', '91-180', '180+'];

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * {@inheritdoc}
     */
    public function summary(array $filters = []): array
    {
        $asOf = $filters['as_of_date'] ?? now()->toDateString();
        $perPage = max(1, (int) ($filters['per_page'] ?? 25));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;
        $bucket = $filters['bucket'] ?? null;

        $base = $this->baseArrearsQuery($asOf, $filters);

        // ── Aggregate per loan ─────────────────────────────────────────────
        $base->selectRaw('l.id                                                     as loan_id')
            ->selectRaw('l.loan_no')
            ->selectRaw('l.principal')
            ->selectRaw('l.outstanding_balance')
            ->selectRaw('l.loan_officer_id')
            ->selectRaw('l.branch_id')
            ->selectRaw('l.loan_product_id')
            ->selectRaw('m.id                                                     as member_id')
            ->selectRaw('m.name                                                   as member_name')
            ->selectRaw('m.member_number                                             as member_no')
            ->selectRaw("COALESCE(p.name, '')                                     as product_name")
            ->selectRaw("COALESCE(b.name, 'Unassigned')                           as branch_name")
            ->selectRaw("COALESCE(s.name, '—')                                    as loan_officer_name")
            ->selectRaw('
                COALESCE(
                    SUM(
                        GREATEST(0,
                            (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                            - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid)
                        )
                    ), 0
                )                                                                  as total_arrears
             ')
            ->selectRaw('
                COALESCE(
                    SUM(
                        GREATEST(0, rs.principal_due - rs.principal_paid)
                    ), 0
                )                                                                  as principal_arrears
             ')
            ->selectRaw('
                COALESCE(
                    SUM(
                        GREATEST(0, rs.interest_due - rs.interest_paid)
                    ), 0
                )                                                                  as interest_arrears
             ')
            ->selectRaw('
                COALESCE(
                    SUM(
                        GREATEST(0, rs.charges_due - rs.charges_paid)
                    ), 0
                )                                                                  as charges_arrears
             ')
            ->selectRaw('
                COALESCE(
                    SUM(
                        GREATEST(0, rs.penalty_due - rs.penalty_paid)
                    ), 0
                )                                                                  as penalty_arrears
             ')
            ->selectRaw('MAX(DATEDIFF(?, rs.due_date) - COALESCE(p.grace_period, 0)) as days_past_due', [$asOf])
            ->selectRaw('MAX(lt_last.payment_date)                            as last_payment_date')
            ->selectRaw('CASE WHEN l.reschedule_loan_parent_id IS NOT NULL THEN 1 ELSE 0 END as is_rescheduled')
             // ── Per-bucket arrears breakdown ────────────────────────────────
            ->selectRaw('
                COALESCE(SUM(CASE WHEN DATEDIFF(?, rs.due_date) - COALESCE(p.grace_period, 0) BETWEEN 1 AND 30
                    THEN GREATEST(0, (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                                    - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid))
                    ELSE 0 END), 0) as arrears_1_30
             ', [$asOf])
            ->selectRaw('
                COALESCE(SUM(CASE WHEN DATEDIFF(?, rs.due_date) - COALESCE(p.grace_period, 0) BETWEEN 31 AND 60
                    THEN GREATEST(0, (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                                    - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid))
                    ELSE 0 END), 0) as arrears_31_60
             ', [$asOf])
            ->selectRaw('
                COALESCE(SUM(CASE WHEN DATEDIFF(?, rs.due_date) - COALESCE(p.grace_period, 0) BETWEEN 61 AND 90
                    THEN GREATEST(0, (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                                    - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid))
                    ELSE 0 END), 0) as arrears_61_90
             ', [$asOf])
            ->selectRaw('
                COALESCE(SUM(CASE WHEN DATEDIFF(?, rs.due_date) - COALESCE(p.grace_period, 0) BETWEEN 91 AND 180
                    THEN GREATEST(0, (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                                    - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid))
                    ELSE 0 END), 0) as arrears_91_180
             ', [$asOf])
            ->selectRaw('
                COALESCE(SUM(CASE WHEN DATEDIFF(?, rs.due_date) - COALESCE(p.grace_period, 0) > 180
                    THEN GREATEST(0, (rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                                    - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid))
                    ELSE 0 END), 0) as arrears_180plus
             ', [$asOf])
             // ── Total remaining balance from full schedule (not just overdue) ─
            ->selectRaw("
                (SELECT COALESCE(SUM(GREATEST(0,
                    (rs2.principal_due + rs2.interest_due + rs2.charges_due + rs2.penalty_due)
                    - (rs2.principal_paid + rs2.interest_paid + rs2.charges_paid + rs2.penalty_paid)
                )), 0)
                FROM loan_repayment_schedule rs2
                WHERE rs2.loan_id = l.id AND rs2.status != 'paid'
                ) as balance_outstanding
             ")
             // ── Outstanding principal balance (principal component only, all remaining) ─
            ->selectRaw("
                (SELECT COALESCE(SUM(GREATEST(0, rs2.principal_due - rs2.principal_paid)), 0)
                FROM loan_repayment_schedule rs2
                WHERE rs2.loan_id = l.id AND rs2.status != 'paid'
                ) as principal_balance_outstanding
             ")
             // ── Current / not yet due (future scheduled installments) ─────────
            ->selectRaw("
                (SELECT COALESCE(SUM(GREATEST(0,
                    (rs2.principal_due + rs2.interest_due + rs2.charges_due + rs2.penalty_due)
                    - (rs2.principal_paid + rs2.interest_paid + rs2.charges_paid + rs2.penalty_paid)
                )), 0)
                FROM loan_repayment_schedule rs2
                WHERE rs2.loan_id = l.id AND rs2.status != 'paid' AND rs2.due_date > ?
                ) as current_not_yet_due
             ", [$asOf])
             // ── Total paid to date (excluding reversed transactions) ──────────
            ->selectRaw('
                (SELECT COALESCE(SUM(lt2.amount_paid), 0)
                FROM loan_transactions lt2
                WHERE lt2.loan_id = l.id
                  AND lt2.reversal_flag = 0
                  AND lt2.payment_date <= ?
                ) as total_paid_to_date
             ', [$asOf])
            ->groupBy(
                'l.id', 'l.loan_no', 'l.principal', 'l.outstanding_balance',
                'l.reschedule_loan_parent_id',
                'l.loan_officer_id', 'l.branch_id', 'l.loan_product_id',
                'm.id', 'm.name', 'm.member_number',
                'p.name', 'b.name', 's.name',
            );

        // ── Wrap in a subquery to filter by bucket ─────────────────────────
        $inner = DB::connection('tenant')
            ->table(DB::raw("({$base->toSql()}) as loan_aging"))
            ->mergeBindings($base);

        $inner->selectRaw('loan_aging.*')
            ->selectRaw("
                CASE
                    WHEN days_past_due <= 30  THEN '1-30'
                    WHEN days_past_due <= 60  THEN '31-60'
                    WHEN days_past_due <= 90  THEN '61-90'
                    WHEN days_past_due <= 180 THEN '91-180'
                    ELSE '180+'
                END as bucket
              ");

        if ($bucket && in_array($bucket, self::BUCKET_ORDER, true)) {
            $inner->havingRaw("
                CASE
                    WHEN days_past_due <= 30  THEN '1-30'
                    WHEN days_past_due <= 60  THEN '31-60'
                    WHEN days_past_due <= 90  THEN '61-90'
                    WHEN days_past_due <= 180 THEN '91-180'
                    ELSE '180+'
                END = ?
            ", [$bucket]);
        }

        // ── Count total matching rows ───────────────────────────────────────
        $totalQuery = DB::connection('tenant')
            ->table(DB::raw("({$inner->toSql()}) as paged"))
            ->mergeBindings($inner)
            ->selectRaw('COUNT(*) as cnt');

        $total = (int) ($totalQuery->first()->cnt ?? 0);

        // ── Paginate ───────────────────────────────────────────────────────
        $rows = DB::connection('tenant')
            ->table(DB::raw("({$inner->toSql()}) as paged"))
            ->mergeBindings($inner)
            ->orderByRaw("
                FIELD(bucket, '1-30','31-60','61-90','91-180','180+')
            ")
            ->orderBy('total_arrears', 'desc')
            ->limit($perPage)
            ->offset($offset)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        return [
            'data' => $rows,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function portfolioSummary(array $filters = []): array
    {
        $asOf = $filters['as_of_date'] ?? now()->toDateString();

        // ── Total active portfolio (denominator for PAR) ───────────────────
        $activeStatuses = ['disbursed', 'active', 'arrears'];

        // ── Total active portfolio as of $asOf ────────────────────────────────
        // Minimum fix : only loans disbursed on or before as_of_date.
        // Full fix    : balance = principal − principal paid up to as_of_date
        //               (not the stored outstanding_balance, which always reflects
        //                today's value and makes all historical reports wrong).
        $portfolioTotals = DB::connection('tenant')
            ->table('loans as l')
            ->whereNull('l.deleted_at')
            ->whereIn('l.status', $activeStatuses)
            ->where('l.disbursed_at', '<=', $asOf)
            ->when($this->effectiveBranchId($filters), fn ($q, $branchId) => $q->where('l.branch_id', $branchId))
            ->when($filters['loan_product_id'] ?? null, fn ($q, $id) => $q->where('l.loan_product_id', $id))
            ->when($filters['loan_officer_id'] ?? null, fn ($q, $id) => $q->where('l.loan_officer_id', $id))
            ->selectRaw('
                COALESCE(SUM(GREATEST(0,
                    l.principal - COALESCE((
                        SELECT SUM(lt.principal_portion)
                        FROM loan_transactions lt
                        WHERE lt.loan_id = l.id
                          AND lt.reversal_flag = 0
                          AND lt.payment_date <= ?
                    ), 0)
                )), 0) as total_portfolio
            ', [$asOf])
            ->selectRaw('COUNT(l.id) as total_loans')
            ->first();

        $totalPortfolio = (float) ($portfolioTotals->total_portfolio ?? 0);

        // ── Per-installment overdue rows (same base as summary()) ──────────
        $base = $this->baseArrearsQuery($asOf, $filters);

        $base->selectRaw('l.id                                                       as loan_id')
            ->selectRaw("
                CASE
                    WHEN DATEDIFF(?, rs.due_date) - COALESCE(p.grace_period, 0) <= 30  THEN '1-30'
                    WHEN DATEDIFF(?, rs.due_date) - COALESCE(p.grace_period, 0) <= 60  THEN '31-60'
                    WHEN DATEDIFF(?, rs.due_date) - COALESCE(p.grace_period, 0) <= 90  THEN '61-90'
                    WHEN DATEDIFF(?, rs.due_date) - COALESCE(p.grace_period, 0) <= 180 THEN '91-180'
                    ELSE '180+'
                END as bucket
             ", [$asOf, $asOf, $asOf, $asOf])
            ->selectRaw('GREATEST(0, rs.principal_due - rs.principal_paid)          as principal_arrears')
            ->selectRaw('GREATEST(0, rs.interest_due  - rs.interest_paid)           as interest_arrears')
            ->selectRaw('GREATEST(0, rs.charges_due   - rs.charges_paid)            as charges_arrears')
            ->selectRaw('GREATEST(0, rs.penalty_due   - rs.penalty_paid)            as penalty_arrears');

        // ── Aggregate installment rows by bucket ───────────────────────────
        $bucketRows = DB::connection('tenant')
            ->table(DB::raw("({$base->toSql()}) as installment_aging"))
            ->mergeBindings($base)
            ->selectRaw('bucket')
            ->selectRaw('COUNT(DISTINCT loan_id)          as loan_count')
            ->selectRaw('SUM(principal_arrears)            as principal_arrears')
            ->selectRaw('SUM(interest_arrears)             as interest_arrears')
            ->selectRaw('SUM(charges_arrears)              as charges_arrears')
            ->selectRaw('SUM(penalty_arrears)              as penalty_arrears')
            ->selectRaw('
                SUM(principal_arrears)
                + SUM(interest_arrears)
                + SUM(charges_arrears)
                + SUM(penalty_arrears)                     as total_arrears
            ')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        // ── PAR: outstanding balance of loans with DPD > 30 / > 90 ──────────
        // Correct SASRA formula: numerator = sum of outstanding_balance of loans
        // where at least one installment has DPD > threshold (not sum of arrears).
        // ── PAR base: per-loan DPD + schedule-based balance as of $asOf ─────
        // Uses the same two fixes as portfolioTotals:
        //   1. disbursed_at <= asOf  (only loans that existed on that date)
        //   2. balance = principal − principal paid up to asOf
        $parBase = DB::connection('tenant')
            ->table('loans as l')
            ->join('loan_repayment_schedule as rs', 'rs.loan_id', '=', 'l.id')
            ->leftJoin('loan_products as p', 'p.id', '=', 'l.loan_product_id')
            ->whereNull('l.deleted_at')
            ->whereIn('l.status', $activeStatuses)
            ->where('l.disbursed_at', '<=', $asOf)
            ->where('rs.status', '!=', 'paid')
            ->where('rs.due_date', '<', $asOf)
            // Honour grace period — same rule as baseArrearsQuery()
            ->whereRaw('DATEDIFF(?, rs.due_date) > COALESCE(p.grace_period, 0)', [$asOf])
            ->when($this->effectiveBranchId($filters), fn ($q, $id) => $q->where('l.branch_id', $id))
            ->when($filters['loan_product_id'] ?? null, fn ($q, $id) => $q->where('l.loan_product_id', $id))
            ->when($filters['loan_officer_id'] ?? null, fn ($q, $id) => $q->where('l.loan_officer_id', $id))
            ->selectRaw('l.id')
            ->selectRaw('
                GREATEST(0, l.principal - COALESCE((
                    SELECT SUM(lt.principal_portion)
                    FROM loan_transactions lt
                    WHERE lt.loan_id = l.id
                      AND lt.reversal_flag = 0
                      AND lt.payment_date <= ?
                ), 0)) as outstanding_balance_asof
            ', [$asOf])
            ->selectRaw('l.reschedule_loan_parent_id')
            ->selectRaw('MAX(DATEDIFF(?, rs.due_date) - COALESCE(p.grace_period, 0)) as max_dpd', [$asOf])
            ->groupBy('l.id', 'l.principal', 'l.reschedule_loan_parent_id');

        $parTotals = DB::connection('tenant')
            ->table(DB::raw("({$parBase->toSql()}) as par_loans"))
            ->mergeBindings($parBase)
            // Total PAR (regulatory — rescheduled loans included, SASRA-compliant)
            ->selectRaw('COALESCE(SUM(CASE WHEN max_dpd > 30 THEN outstanding_balance_asof ELSE 0 END), 0) as par30_balance')
            ->selectRaw('COALESCE(SUM(CASE WHEN max_dpd > 90 THEN outstanding_balance_asof ELSE 0 END), 0) as par90_balance')
            // PAR excluding rescheduled loans (portfolio health view)
            ->selectRaw('COALESCE(SUM(CASE WHEN max_dpd > 30 AND reschedule_loan_parent_id IS NULL THEN outstanding_balance_asof ELSE 0 END), 0) as par30_balance_excl')
            ->selectRaw('COALESCE(SUM(CASE WHEN max_dpd > 90 AND reschedule_loan_parent_id IS NULL THEN outstanding_balance_asof ELSE 0 END), 0) as par90_balance_excl')
            // Rescheduled loan stats
            ->selectRaw('COUNT(CASE WHEN reschedule_loan_parent_id IS NOT NULL THEN 1 END) as rescheduled_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN reschedule_loan_parent_id IS NOT NULL THEN outstanding_balance_asof ELSE 0 END), 0) as rescheduled_outstanding')
            // Distinct count of loans actually in arrears (after grace period)
            ->selectRaw('COUNT(*) as arrears_loan_count')
            // Per-bucket loan count by WORST DPD — each loan belongs to exactly one bucket
            // so these sum to arrears_loan_count and the Loans column is consistent with the Total
            ->selectRaw('COUNT(CASE WHEN max_dpd BETWEEN 1  AND 30  THEN 1 END) as count_1_30')
            ->selectRaw('COUNT(CASE WHEN max_dpd BETWEEN 31 AND 60  THEN 1 END) as count_31_60')
            ->selectRaw('COUNT(CASE WHEN max_dpd BETWEEN 61 AND 90  THEN 1 END) as count_61_90')
            ->selectRaw('COUNT(CASE WHEN max_dpd BETWEEN 91 AND 180 THEN 1 END) as count_91_180')
            ->selectRaw('COUNT(CASE WHEN max_dpd > 180              THEN 1 END) as count_180plus')
            ->first();

        $par30Balance = (float) ($parTotals->par30_balance ?? 0);
        $par90Balance = (float) ($parTotals->par90_balance ?? 0);
        $par30BalanceExcl = (float) ($parTotals->par30_balance_excl ?? 0);
        $par90BalanceExcl = (float) ($parTotals->par90_balance_excl ?? 0);

        // ── Build ordered bucket list ──────────────────────────────────────
        $buckets = [];
        $totalArrears = 0.0;
        $totalProvision = 0.0;

        // Map bucket key → the per-bucket loan count field from $parTotals
        $bucketCountField = [
            '1-30' => 'count_1_30',
            '31-60' => 'count_31_60',
            '61-90' => 'count_61_90',
            '91-180' => 'count_91_180',
            '180+' => 'count_180plus',
        ];

        foreach (self::BUCKET_ORDER as $bucketKey) {
            $row = $bucketRows->get($bucketKey);
            $bucketArrears = (float) ($row->total_arrears ?? 0);
            $provisionRate = self::PROVISION_RATES[$bucketKey];
            $provisionAmt = round($bucketArrears * $provisionRate / 100, 2);
            $portfolioPct = $totalPortfolio > 0
                ? round($bucketArrears / $totalPortfolio * 100, 2)
                : 0.0;

            // Use worst-DPD count so each loan is counted in exactly one bucket
            $countField = $bucketCountField[$bucketKey];

            $buckets[] = [
                'bucket' => $bucketKey,
                'loan_count' => (int) ($parTotals->{$countField} ?? 0),
                'principal_arrears' => (float) ($row->principal_arrears ?? 0),
                'interest_arrears' => (float) ($row->interest_arrears ?? 0),
                'charges_arrears' => (float) ($row->charges_arrears ?? 0),
                'penalty_arrears' => (float) ($row->penalty_arrears ?? 0),
                'total_arrears' => $bucketArrears,
                'portfolio_percentage' => $portfolioPct,
                'provision_rate' => $provisionRate,
                'provision_amount' => $provisionAmt,
            ];

            $totalArrears += $bucketArrears;
            $totalProvision += $provisionAmt;
        }

        // PAR 30 / 90 — outstanding balance of at-risk loans ÷ total portfolio
        $par30 = $totalPortfolio > 0 ? round($par30Balance / $totalPortfolio * 100, 2) : 0.0;
        $par90 = $totalPortfolio > 0 ? round($par90Balance / $totalPortfolio * 100, 2) : 0.0;

        // PAR excl. rescheduled — board health view (removes evergreened loans)
        $par30ExclRescheduled = $totalPortfolio > 0 ? round($par30BalanceExcl / $totalPortfolio * 100, 2) : 0.0;
        $par90ExclRescheduled = $totalPortfolio > 0 ? round($par90BalanceExcl / $totalPortfolio * 100, 2) : 0.0;

        return [
            'buckets' => $buckets,
            'totals' => [
                'loan_count' => (int) ($portfolioTotals->total_loans ?? 0),
                'arrears_loan_count' => (int) ($parTotals->arrears_loan_count ?? 0),
                'total_arrears' => round($totalArrears, 2),
                'total_portfolio' => round($totalPortfolio, 2),
                'par_30' => $par30,
                'par_90' => $par90,
                'par_30_excl_rescheduled' => $par30ExclRescheduled,
                'par_90_excl_rescheduled' => $par90ExclRescheduled,
                'rescheduled_loan_count' => (int) ($parTotals->rescheduled_count ?? 0),
                'rescheduled_outstanding' => round((float) ($parTotals->rescheduled_outstanding ?? 0), 2),
                'total_provision' => round($totalProvision, 2),
            ],
        ];
    }

    // ─── Shared base query ────────────────────────────────────────────────────

    /**
     * Builds the shared FROM + JOIN + WHERE clause used by both summary() and
     * portfolioSummary(). Does NOT add SELECT or GROUP BY — callers do that.
     *
     * Joins:
     *   loans (l) → loan_repayment_schedule (rs) → loan_products (p)
     *            → members (m) → branches (b) → staff (s, loan officer)
     *            → loan_transactions (lt_last, latest repayment per loan)
     *
     * Filters applied here:
     *   - loan status IN (disbursed, active, arrears) — never closed/written_off
     *   - installment status NOT 'paid'
     *   - installment due_date < as_of_date (overdue only)
     *   - grace period: skip installments within grace_period days of as_of_date
     *   - branch_id / loan_officer_id / loan_product_id from $filters
     */
    private function baseArrearsQuery(string $asOf, array $filters)
    {
        $activeStatuses = ['disbursed', 'active', 'arrears'];

        // Last repayment date per loan (left-joined so loans with no payments still appear)
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
            ->whereIn('l.status', $activeStatuses)
            ->where('rs.status', '!=', 'paid')
            ->where('rs.due_date', '<', $asOf)
            // Grace period: exclude installments still within the product's grace window
            ->whereRaw(
                'DATEDIFF(?, rs.due_date) > COALESCE(p.grace_period, 0)',
                [$asOf]
            );

        // ── Scope filters ─────────────────────────────────────────────────
        $effectiveBranch = $this->effectiveBranchId($filters);
        if ($effectiveBranch) {
            $query->where('l.branch_id', $effectiveBranch);
        }

        if (! empty($filters['loan_officer_id'])) {
            $query->where('l.loan_officer_id', (int) $filters['loan_officer_id']);
        }

        if (! empty($filters['loan_product_id'])) {
            $query->where('l.loan_product_id', (int) $filters['loan_product_id']);
        }

        return $query;
    }

    // ─── Branch scope helper ──────────────────────────────────────────────────

    /**
     * Resolves the effective branch_id to apply to queries, honouring the
     * same BranchContext rules used in the rest of the ReportsController.
     *
     * - SCOPE_ALL: use $filters['branch_id'] if provided, otherwise no restriction
     * - SCOPE_BRANCH / SCOPE_SELF: always restrict to the viewer's assigned branch
     */
    private function effectiveBranchId(array $filters): ?int
    {
        $viewer = auth()->user();

        if (! $viewer instanceof Staff) {
            return isset($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        }

        return match (BranchContext::scopeFor($viewer)) {
            BranchContext::SCOPE_ALL => isset($filters['branch_id']) ? (int) $filters['branch_id'] : null,
            default => $viewer->branch_id ? (int) $viewer->branch_id : null,
        };
    }
}
