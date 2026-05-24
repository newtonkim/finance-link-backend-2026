<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\LoanApplication;
use Illuminate\Pagination\LengthAwarePaginator;

interface LoanApplicationServiceInterface
{
    /**
     * Return a paginated list of loan applications, optionally filtered.
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Create a new loan application in draft status.
     */
    public function create(array $data): LoanApplication;

    /**
     * Update an existing draft loan application.
     */
    public function update(LoanApplication $application, array $data): bool;

    /**
     * Submit a draft application for review.
     */
    public function submit(LoanApplication $application): bool;

    /**
     * Cancel a loan application with a mandatory reason.
     */
    public function cancel(LoanApplication $application, string $reason): bool;

    /**
     * Reopen a cancelled application back to draft for corrections.
     */
    public function reopen(LoanApplication $application): bool;
}
