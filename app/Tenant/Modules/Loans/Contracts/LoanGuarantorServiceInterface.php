<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Models\Member;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;

interface LoanGuarantorServiceInterface
{
    public function addGuarantor(
        LoanApplication $application,
        int $memberId,
        float $guaranteeAmount,
        ?string $notes,
        int $actorId
    ): LoanApplicationGuarantor;

    public function removeGuarantor(LoanApplicationGuarantor $guarantor): void;

    /** True when the required min_guarantors count is met. */
    public function validateAdequacy(LoanApplication $application): bool;

    /** True when the member's active guarantee commitments are within acceptable limits. */
    public function validateGuarantorCapacity(Member $member): bool;
}
