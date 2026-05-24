<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\Loan;

interface LoanWriteOffServiceInterface
{
    public function writeOff(Loan $loan, int $actorId, string $narration = ''): void;
}
