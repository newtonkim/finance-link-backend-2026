<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\Loan;
use Illuminate\Support\Collection;

interface LoanActivityServiceInterface
{
    /**
     * Return a chronological list of all events on the given loan.
     *
     * Each item is an array with keys:
     *  - type        string  (status_change | payment_received | charge_applied | disbursement | created)
     *  - title       string  Short human-readable title
     *  - description string  Full description
     *  - actor       array|null  ['id' => int, 'name' => string]
     *  - amount      string|null  Formatted monetary amount (present on disbursed, repayment, penalty_assessed)
     *  - notes       string|null
     *  - timestamp   Carbon
     *
     * @return Collection<int, array>
     */
    public function getActivity(Loan $loan): Collection;
}
