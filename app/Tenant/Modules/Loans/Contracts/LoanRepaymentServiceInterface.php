<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanTransaction;
use Illuminate\Validation\ValidationException;

interface LoanRepaymentServiceInterface
{
    /**
     * Post a repayment against a loan.
     *
     * Allocation order per installment (due_date ASC):
     *   penalty → charges → interest → principal
     *
     * @param  array{payment_method:string, payment_date:string, receipt_no?:string, notes?:string}  $data
     */
    public function post(Loan $loan, float $amount, array $data, int $actorId): LoanTransaction;

    /**
     * Post a repayment by debiting the member's savings account.
     *
     * Runs the same allocation waterfall as post() but:
     *  - Debits savings_accounts.balance instead of recording a cash receipt.
     *  - Posts DR Member Savings Liability (2111) / CR Loan Portfolio + income accounts.
     *  - Records a savings Transaction of type 'loan_repayment'.
     *  - Links SubLedger entries to both the savings account and the loan.
     *
     * @param  array{savings_account_id:int, amount:float, payment_date:string, loan_officer_id?:int, notes?:string}  $data
     *
     * @throws ValidationException If account balance is insufficient.
     * @throws \Throwable Any DB/accounting error rolls back the full transaction.
     */
    public function repayFromSavings(Loan $loan, array $data, int $actorId): LoanTransaction;

    /**
     * Preview allocation without persisting anything.
     *
     * @return array{total: float, schedules: array, penalty: float, charges: float, interest: float, principal: float, overpayment: float}
     */
    public function preview(Loan $loan, float $amount): array;
}
