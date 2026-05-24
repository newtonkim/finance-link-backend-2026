<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\Loan;

interface LoanPenaltyCalculatorServiceInterface
{
    /**
     * Assess penalties on all overdue loans.
     *
     * For each loan with overdue schedules that have exceeded the product's
     * grace_period, calculates and persists penalty_due on the schedule,
     * posts the GL accrual entry (DR Penalty Receivable / CR Penalty Income),
     * and optionally flags the loan as `arrears`.
     *
     * @return array{assessed: int, skipped: int}
     */
    public function assessAll(): array;

    /**
     * Assess penalty for a single overdue schedule row.
     * Called by assessAll() and by CheckLoanArrearsCommand.
     */
    public function assessSchedule(int $scheduleId): void;

    /**
     * Assess penalty for a loan on demand.
     */
    public function assessLoan(Loan $loan): void;
}
