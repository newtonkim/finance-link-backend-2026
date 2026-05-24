<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

interface LoanDisbursementServiceInterface
{
    /**
     * Disburse an approved loan application atomically.
     *
     * Creates the Loan record, repayment schedule, and accounting postings
     * inside a single database transaction. Transitions the application from
     * approved → disbursement_pending → disbursed.
     *
     * @param  LoanApplication  $application  Must be in 'approved' status.
     * @param  array  $data  disbursement_method, disbursement_reference, disbursement_date, notes
     * @param  int|null  $actorId  Staff ID performing the disbursement.
     *
     * @throws ValidationException Pre-flight failures.
     * @throws \Throwable Any DB/accounting error rolls back the transaction.
     */
    public function disburse(LoanApplication $application, array $data, ?int $actorId): Loan;

    /**
     * Regenerate the repayment schedule for an existing loan using a new
     * schedule start date.  Deletes all unpaid schedule rows and re-creates
     * them.  Already-paid installments are preserved.
     *
     * @param  Loan  $loan  The loan whose schedule should be regenerated.
     * @param  Carbon  $newScheduleDate  The new schedule start date.
     */
    public function regenerateSchedule(Loan $loan, Carbon $newScheduleDate): void;

    /**
     * Post the disbursement journal entry for a top-up loan created via the
     * express (auto-disburse) flow. The standard disburse() path cannot be
     * used here because no LoanApplication exists for the topup.
     *
     * DR  Loan Portfolio Account  = principal
     * CR  Disbursement Account    = principal
     *
     * @param  Loan  $newLoan  The newly created topup loan.
     * @param  int|null  $actorId  Staff ID performing the action.
     */
    public function postTopupDisbursementEntry(Loan $newLoan, ?int $actorId): void;
}
