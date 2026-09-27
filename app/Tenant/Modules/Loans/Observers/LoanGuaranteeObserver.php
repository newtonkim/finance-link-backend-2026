<?php

namespace App\Tenant\Modules\Loans\Observers;

use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;

/**
 * Releases a loan's guarantees when it closes. Loans close from more than one place
 * (repayment, repayment from savings, top-up), so this hangs off the model rather
 * than each of them.
 */
class LoanGuaranteeObserver
{
    public function __construct(
        protected LoanGuarantorServiceInterface $guarantors,
    ) {}

    public function updated(Loan $loan): void
    {
        if (! $loan->wasChanged('status')) {
            return;
        }

        $status = $loan->status instanceof LoanStatus ? $loan->status : LoanStatus::tryFrom((string) $loan->status);

        if ($status === LoanStatus::Closed) {
            $this->guarantors->releaseForLoan($loan);
        }
    }
}
