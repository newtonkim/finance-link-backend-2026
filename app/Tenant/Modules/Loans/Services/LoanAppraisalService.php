<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Staff;
use App\Tenant\Modules\Loans\Contracts\LoanAppraisalServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanDocumentServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApprovalSetting;
use Illuminate\Validation\ValidationException;

class LoanAppraisalService implements LoanAppraisalServiceInterface
{
    public function __construct(
        protected LoanApplicationStatusGuard $statusGuard,
        protected LoanDocumentServiceInterface $documentService,
    ) {}

    /**
     * Take a submitted application into review (submitted → under_review).
     *
     * @throws ValidationException
     */
    public function takeForReview(LoanApplication $application): bool
    {
        $reviewableStatuses = [
            LoanApplication::STATUS_SUBMITTED,
            LoanApplication::STATUS_RETURNED_FOR_CORRECTION,
        ];

        if (! in_array($application->status, $reviewableStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot take for review: application must be in 'submitted' or 'returned_for_correction' status (current: '{$application->status}').",
                ],
            ]);
        }

        $missing = $this->documentService->missingRequiredForStage($application, 'submission');
        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'documents' => ['Required submission documents are missing: '.implode(', ', $missing)],
            ]);
        }

        $this->statusGuard->transition(
            $application,
            LoanApplication::STATUS_UNDER_REVIEW,
            'Application taken for review.'
        );

        $application->update([
            'reviewed_by' => auth()->id() ?? auth('tenant')->id(),
            'reviewed_at' => now(),
        ]);

        return true;
    }

    /**
     * Record appraisal details and recommend the application (under_review → recommended).
     *
     * @throws ValidationException
     */
    public function appraise(LoanApplication $application, array $data): bool
    {
        $this->assertStatus($application, LoanApplication::STATUS_UNDER_REVIEW, 'appraise');
        $this->assertIsBranchManager('recommend');

        $application->update([
            'recommended_amount' => $data['recommended_amount'],
            'recommended_term' => $data['recommended_term'],
            'recommended_interest_rate' => $data['recommended_interest_rate'],
            'appraisal_notes' => $data['appraisal_notes'] ?? null,
            'officer_notes' => $data['officer_notes'] ?? $data['appraisal_notes'] ?? null,
            'risk_rating' => $data['risk_rating'],
            'recommended_by' => auth()->id() ?? auth('tenant')->id(),
            'recommended_at' => now(),
        ]);

        // Use three-tier flow if committee voting is enabled for this product
        if ($this->isCommitteeVotingEnabled($application)) {
            $this->statusGuard->transition(
                $application,
                LoanApplication::STATUS_OFFICER_RECOMMENDED,
                $data['appraisal_notes'] ?? null
            );
        } else {
            $this->statusGuard->transition(
                $application,
                LoanApplication::STATUS_RECOMMENDED,
                $data['appraisal_notes'] ?? null
            );
        }

        return true;
    }

    /**
     * Flag the application as awaiting additional documents (under_review → awaiting_documents).
     *
     * @throws ValidationException
     */
    public function requestDocuments(LoanApplication $application, string $note): bool
    {
        $this->assertStatus($application, LoanApplication::STATUS_UNDER_REVIEW, 'request documents');

        $this->statusGuard->transition(
            $application,
            LoanApplication::STATUS_AWAITING_DOCUMENTS,
            $note
        );

        return true;
    }

    /**
     * Return the application to draft for member corrections (under_review → draft).
     *
     * @throws ValidationException
     */
    public function returnForCorrection(LoanApplication $application, string $reason): bool
    {
        $this->assertStatus($application, LoanApplication::STATUS_UNDER_REVIEW, 'return for correction');

        $application->update([
            'return_reason' => $reason,
            'returned_by' => auth()->id() ?? auth('tenant')->id(),
            'returned_at' => now(),
        ]);

        $this->statusGuard->transition(
            $application,
            LoanApplication::STATUS_DRAFT,
            $reason
        );

        return true;
    }

    /**
     * Resume review after the member provides requested documents.
     * Allowed from: awaiting_documents → under_review.
     *
     * @throws ValidationException
     */
    public function resumeReview(LoanApplication $application): bool
    {
        if ($application->status !== LoanApplication::STATUS_AWAITING_DOCUMENTS) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot resume review: application must be in 'awaiting_documents' status (current: '{$application->status}').",
                ],
            ]);
        }

        $this->statusGuard->transition(
            $application,
            LoanApplication::STATUS_UNDER_REVIEW,
            'Review resumed — documents have been provided.'
        );

        return true;
    }

    /**
     * Reject the application at the appraisal stage (under_review → rejected).
     *
     * @throws ValidationException
     */
    public function reject(LoanApplication $application, string $reason): bool
    {
        $this->assertStatus($application, LoanApplication::STATUS_UNDER_REVIEW, 'reject');
        $this->assertIsBranchManager('reject');

        $application->update([
            'rejection_reason' => $reason,
            'rejected_by' => auth()->id() ?? auth('tenant')->id(),
            'rejected_at' => now(),
        ]);

        $this->statusGuard->transition(
            $application,
            LoanApplication::STATUS_REJECTED,
            $reason
        );

        return true;
    }

    /**
     * Guard that the application is in the expected status before performing an action.
     *
     * @throws ValidationException
     */
    private function assertStatus(LoanApplication $application, string $expected, string $action): void
    {
        if ($application->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot {$action}: application must be in '{$expected}' status (current: '{$application->status}').",
                ],
            ]);
        }
    }

    /**
     * Ensure the authenticated staff member has branch manager privileges.
     *
     * @throws ValidationException
     */
    private function assertIsBranchManager(string $action): void
    {
        $staff = auth()->user() ?? auth('tenant')->user();
        $isAdmin = ($staff instanceof Staff) && ($staff->is_tenant_admin || strtolower($staff->role) === 'admin');
        
        if (! ($staff instanceof Staff) || (! $staff->can_manage_branch && ! $isAdmin)) {
            throw ValidationException::withMessages([
                'permissions' => ["Only branch managers are allowed to {$action} applications."],
            ]);
        }
    }

    /**
     * Check if committee voting is enabled for the loan product.
     */
    private function isCommitteeVotingEnabled(LoanApplication $application): bool
    {
        return LoanApprovalSetting::where(
            'loan_product_id',
            $application->loan_product_id
        )->exists();
    }
}
