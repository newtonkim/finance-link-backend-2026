<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationApproval;

interface LoanApprovalServiceInterface
{
    /**
     * Record an approval vote for a recommended application.
     * Auto-transitions to 'approved' once the minimum approver threshold is met.
     */
    public function approve(LoanApplication $application, ?string $comments): LoanApplicationApproval;

    /**
     * Record a rejection vote and immediately transition the application to 'rejected'.
     */
    public function decline(LoanApplication $application, string $reason): LoanApplicationApproval;
}
