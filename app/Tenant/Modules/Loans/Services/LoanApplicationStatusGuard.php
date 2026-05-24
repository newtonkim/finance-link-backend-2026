<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationStatusHistory;
use Illuminate\Validation\ValidationException;

class LoanApplicationStatusGuard
{
    /**
     * All valid status transitions.
     * Keys are the current (from) status; values are the allowed next (to) statuses.
     */
    private const TRANSITIONS = [
        LoanApplication::STATUS_DRAFT => [
            LoanApplication::STATUS_SUBMITTED,
            LoanApplication::STATUS_CANCELLED,
        ],
        LoanApplication::STATUS_SUBMITTED => [
            LoanApplication::STATUS_UNDER_REVIEW,
            LoanApplication::STATUS_CANCELLED,
        ],
        LoanApplication::STATUS_UNDER_REVIEW => [
            LoanApplication::STATUS_AWAITING_DOCUMENTS,
            LoanApplication::STATUS_RECOMMENDED,              // legacy simple flow
            LoanApplication::STATUS_OFFICER_RECOMMENDED,      // three-tier flow
            LoanApplication::STATUS_REJECTED,
            LoanApplication::STATUS_CANCELLED,
            LoanApplication::STATUS_DRAFT,                    // return for correction
        ],
        LoanApplication::STATUS_AWAITING_DOCUMENTS => [
            LoanApplication::STATUS_UNDER_REVIEW,
            LoanApplication::STATUS_CANCELLED,
        ],
        // Legacy simple flow
        LoanApplication::STATUS_RECOMMENDED => [
            LoanApplication::STATUS_APPROVED,
            LoanApplication::STATUS_REJECTED,
        ],
        // Three-tier flow: Officer recommended
        LoanApplication::STATUS_OFFICER_RECOMMENDED => [
            LoanApplication::STATUS_BM_RECOMMENDED,
            LoanApplication::STATUS_RETURNED_FOR_CORRECTION,
            LoanApplication::STATUS_CANCELLED,
        ],
        // Three-tier flow: Returned for correction
        LoanApplication::STATUS_RETURNED_FOR_CORRECTION => [
            LoanApplication::STATUS_SUBMITTED,          // applicant corrects and re-submits
            LoanApplication::STATUS_UNDER_REVIEW,
            LoanApplication::STATUS_CANCELLED,
        ],
        // Three-tier flow: BM recommended
        LoanApplication::STATUS_BM_RECOMMENDED => [
            LoanApplication::STATUS_COMMITTEE_VOTING,
            LoanApplication::STATUS_CANCELLED,
        ],
        // Three-tier flow: Committee voting
        LoanApplication::STATUS_COMMITTEE_VOTING => [
            LoanApplication::STATUS_APPROVED,
            LoanApplication::STATUS_DECLINED,
            LoanApplication::STATUS_CANCELLED,
        ],
        // Three-tier flow: Declined
        LoanApplication::STATUS_DECLINED => [],
        LoanApplication::STATUS_APPROVED => [
            LoanApplication::STATUS_DISBURSEMENT_PENDING,
        ],
        LoanApplication::STATUS_DISBURSEMENT_PENDING => [
            LoanApplication::STATUS_DISBURSED,
        ],
        LoanApplication::STATUS_REJECTED => [],
        LoanApplication::STATUS_DISBURSED => [],
        LoanApplication::STATUS_CANCELLED => [
            LoanApplication::STATUS_DRAFT,   // reopen for corrections
        ],
    ];

    /**
     * Check whether the given transition is allowed without throwing.
     */
    public function canTransition(LoanApplication $application, string $toStatus): bool
    {
        $allowed = self::TRANSITIONS[$application->status] ?? [];

        return in_array($toStatus, $allowed, true);
    }

    /**
     * Validate the transition, update the application status, and record the history entry.
     *
     * @throws ValidationException
     */
    public function transition(LoanApplication $application, string $toStatus, ?string $notes = null): void
    {
        if (! $this->canTransition($application, $toStatus)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot transition from '{$application->status}' to '{$toStatus}'.",
                ],
            ]);
        }

        $fromStatus = $application->status;

        $application->status = $toStatus;
        $application->save();

        LoanApplicationStatusHistory::create([
            'loan_application_id' => $application->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by' => auth('tenant')->id(),
            'notes' => $notes,
            'ip_address' => request()->ip(),
            'changed_at' => now(),
        ]);
    }
}
