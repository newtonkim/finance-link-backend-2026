<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Staff;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApprovalVote;

class LoanPermissionsService
{
    /**
     * Build the permitted_actions array for a given loan application and staff member.
     */
    public function forUser(LoanApplication $application, ?Staff $staff): array
    {
        if (! $staff) {
            return [];
        }

        $actions = [];
        $status = $application->status;
        $isAssignedLO = $application->loan_officer_id === $staff->id;
        $isAdmin = $staff->is_tenant_admin || strtolower($staff->role) === 'admin';
        $isBM = $staff->can_manage_branch || $isAdmin;
        $canVote = $staff->can_vote_on_loans || $isAdmin;
        $canFinalise = $staff->can_finalise_loan || $isAdmin;
        $hasVoted = $this->hasVoted($application, $staff);

        // take_for_review
        if ($status === LoanApplication::STATUS_SUBMITTED && ($isAssignedLO || $isBM)) {
            $actions[] = 'take_for_review';
        }

        // request_documents
        if ($status === LoanApplication::STATUS_UNDER_REVIEW && $isAssignedLO) {
            $actions[] = 'request_documents';
        }

        // resume_review
        if ($status === LoanApplication::STATUS_AWAITING_DOCUMENTS && $isAssignedLO) {
            $actions[] = 'resume_review';
        }

        // appraise (legacy simple flow)
        if ($status === LoanApplication::STATUS_UNDER_REVIEW && $isBM) {
            $actions[] = 'appraise';
        }

        // officer_recommend (three-tier flow)
        if ($status === LoanApplication::STATUS_UNDER_REVIEW && $isBM) {
            $actions[] = 'officer_recommend';
        }

        // reject_at_appraisal
        if ($status === LoanApplication::STATUS_UNDER_REVIEW && $isBM) {
            $actions[] = 'reject_at_appraisal';
        }

        // bm_recommend
        if ($status === LoanApplication::STATUS_OFFICER_RECOMMENDED && $isBM) {
            $actions[] = 'bm_recommend';
        }

        // return_for_correction (BM level)
        if ($status === LoanApplication::STATUS_OFFICER_RECOMMENDED && $isBM) {
            $actions[] = 'return_for_correction';
        }

        // cast_vote
        if ($status === LoanApplication::STATUS_COMMITTEE_VOTING && $canVote && ! $hasVoted) {
            $actions[] = 'cast_vote';
        }

        // mark_abstention
        if ($status === LoanApplication::STATUS_COMMITTEE_VOTING && $isBM) {
            $actions[] = 'mark_abstention';
        }

        // confirm_terms
        if ($status === LoanApplication::STATUS_APPROVED && $canFinalise && ! $application->schedule_locked_at) {
            $actions[] = 'confirm_terms';
        }

        // initiate_disbursement
        if ($status === LoanApplication::STATUS_DISBURSEMENT_PENDING && $canFinalise) {
            $actions[] = 'initiate_disbursement';
        }

        // complete_disbursement
        if ($status === LoanApplication::STATUS_DISBURSEMENT_PENDING && $canFinalise) {
            $actions[] = 'complete_disbursement';
        }

        // cancel (any active status)
        if (! in_array($status, [
            LoanApplication::STATUS_REJECTED,
            LoanApplication::STATUS_DISBURSED,
            LoanApplication::STATUS_CANCELLED,
            LoanApplication::STATUS_DECLINED,
        ], true)) {
            $actions[] = 'cancel';
        }

        // reassign_csr / reassign_df (BM or above)
        if ($isBM) {
            $actions[] = 'reassign_csr';
            $actions[] = 'reassign_df';
        }

        return $actions;
    }

    /**
     * Check if a staff member has already voted on this application.
     */
    private function hasVoted(LoanApplication $application, Staff $staff): bool
    {
        return LoanApprovalVote::where('loan_application_id', $application->id)
            ->where('staff_id', $staff->id)
            ->exists();
    }
}
