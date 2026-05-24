<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApprovalVote;

class LoanQuorumEvaluator
{
    public function __construct(
        protected LoanApplicationStatusGuard $statusGuard,
    ) {}

    /**
     * Evaluate quorum after a vote has been cast.
     * Transitions the application to 'approved' or 'declined' if quorum is met.
     */
    public function evaluate(LoanApplication $application): void
    {
        if ($application->status !== LoanApplication::STATUS_COMMITTEE_VOTING) {
            return;
        }

        $votes = LoanApprovalVote::where('loan_application_id', $application->id)->get();

        $total = $votes->count();
        $yes = $votes->where('decision', 'approve')->where('abstained', false)->count();
        $no = $votes->where('decision', 'decline')->where('abstained', false)->count();

        $quorumRequired = $application->quorum_required ?? 1;
        $approvalThreshold = $application->approval_threshold ?? 1;
        $unanimityRequired = $application->unanimity_required ?? false;

        // Wait for more votes if quorum not met
        if ($total < $quorumRequired) {
            return;
        }

        // If unanimity is required and there's any 'no' vote, decline
        if ($unanimityRequired && $no > 0) {
            $this->statusGuard->transition(
                $application,
                LoanApplication::STATUS_DECLINED,
                "Application declined: unanimity required but {$no} decline vote(s) cast."
            );

            return;
        }

        // If approval threshold is met, approve
        if ($yes >= $approvalThreshold) {
            $application->update([
                'approved_by' => auth()->id() ?? auth('tenant')->id(),
                'approved_at' => now(),
                'approved_amount' => $application->final_approved_amount ?? $application->recommended_amount,
                'approved_term' => $application->final_approved_term ?? $application->recommended_term,
            ]);

            $this->statusGuard->transition(
                $application,
                LoanApplication::STATUS_APPROVED,
                "Approved after {$yes} of {$quorumRequired} required votes."
            );

            return;
        }

        // If it's mathematically impossible to reach the threshold, decline
        $remainingVotes = $quorumRequired - $total;
        $maxPossibleYes = $yes + $remainingVotes;

        if ($maxPossibleYes < $approvalThreshold) {
            $this->statusGuard->transition(
                $application,
                LoanApplication::STATUS_DECLINED,
                "Application declined: cannot reach approval threshold of {$approvalThreshold}."
            );
        }
    }
}
