<?php

namespace App\Tenant\Modules\Loans\Contracts;

interface LoanAgingReportServiceInterface
{
    /**
     * Return loan-level aging rows.
     *
     * Each row represents one active loan that has at least one overdue
     * installment (past due_date, outside grace period, not yet fully paid).
     *
     * Supported filters:
     *   - as_of_date      (string|null) — point-in-time date; defaults to today
     *   - branch_id       (int|null)    — restrict to a single branch
     *   - loan_officer_id (int|null)    — restrict to loans owned by one officer
     *   - loan_product_id (int|null)    — restrict to one loan product
     *   - bucket          (string|null) — one of: 1-30|31-60|61-90|91-180|180+
     *   - per_page        (int)         — rows per page (default 25)
     *   - page            (int)         — current page (default 1)
     *
     * Each returned row contains:
     *   loan_id, loan_no, member_id, member_name, member_no,
     *   product_name, branch_name, loan_officer_name,
     *   principal (original disbursed), outstanding_balance, balance_outstanding,
     *   principal_arrears, interest_arrears, charges_arrears, penalty_arrears,
     *   total_arrears, arrears_1_30, arrears_31_60, arrears_61_90, arrears_91_180, arrears_180plus,
     *   days_past_due, bucket, last_payment_date, is_rescheduled
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, per_page: int, current_page: int}
     */
    public function summary(array $filters = []): array;

    /**
     * Return one aggregated row per aging bucket.
     *
     * Covers all active loans with at least one overdue installment, grouped
     * by the worst aging bucket. PAR is computed as outstanding balance of
     * at-risk loans ÷ total portfolio (SASRA-compliant formula).
     *
     * Supported filters: as_of_date, branch_id, loan_officer_id, loan_product_id
     * (same as summary(), excluding bucket and pagination).
     *
     * Each bucket row contains:
     *   bucket, loan_count, principal_arrears, interest_arrears,
     *   charges_arrears, penalty_arrears, total_arrears,
     *   portfolio_percentage, provision_rate, provision_amount
     *
     * provision_rate follows SASRA prudential guidelines:
     *   1–30 → 5%, 31–60 → 25%, 61–90 → 50%, 91–180 → 75%, 180+ → 100%
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *   buckets: array<int, array<string, mixed>>,
     *   totals: array{
     *     loan_count: int,
     *     total_arrears: float,
     *     total_portfolio: float,
     *     par_30: float,
     *     par_90: float,
     *     par_30_excl_rescheduled: float,
     *     par_90_excl_rescheduled: float,
     *     rescheduled_loan_count: int,
     *     rescheduled_outstanding: float,
     *     total_provision: float
     *   }
     * }
     */
    public function portfolioSummary(array $filters = []): array;
}
