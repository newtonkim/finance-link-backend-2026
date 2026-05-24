<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\LoanApplication;

interface LoanAppraisalServiceInterface
{
    /**
     * Take a submitted application into review (submitted → under_review).
     */
    public function takeForReview(LoanApplication $application): bool;

    /**
     * Record appraisal details and recommend the application (under_review → recommended).
     */
    public function appraise(LoanApplication $application, array $data): bool;

    /**
     * Flag the application as needing additional documents (under_review → awaiting_documents).
     */
    public function requestDocuments(LoanApplication $application, string $note): bool;

    /**
     * Return the application to draft for member corrections (under_review → draft).
     */
    public function returnForCorrection(LoanApplication $application, string $reason): bool;

    /**
     * Resume review after documents were provided (awaiting_documents → under_review).
     */
    public function resumeReview(LoanApplication $application): bool;

    /**
     * Reject the application at the appraisal stage (under_review → rejected).
     */
    public function reject(LoanApplication $application, string $reason): bool;
}
