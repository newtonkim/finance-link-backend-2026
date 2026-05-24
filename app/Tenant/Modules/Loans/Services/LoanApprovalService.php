<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Contracts\LoanApprovalServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanApprovalService implements LoanApprovalServiceInterface
{
    public function __construct(
        protected LoanApplicationStatusGuard $statusGuard,
    ) {}

    /**
     * Record an approval vote.
     * Auto-transitions to 'approved' once the minimum approver threshold is met.
     *
     * @throws ValidationException
     */
    public function approve(LoanApplication $application, ?string $comments): LoanApplicationApproval
    {
        $this->assertRecommended($application);
        $this->assertNotAlreadyVoted($application);

        return DB::transaction(function () use ($application, $comments) {
            $approval = LoanApplicationApproval::create([
                'loan_application_id' => $application->id,
                'approver_id' => auth()->id() ?? auth('tenant')->id() ?? 1,
                'level' => 1,
                'decision' => 'approved',
                'comments' => $comments,
                'decided_at' => now(),
            ]);

            $approvedCount = LoanApplicationApproval::where('loan_application_id', $application->id)
                ->where('decision', 'approved')
                ->count();

            $minApprovers = $this->minApprovers($application);

            if ($approvedCount >= $minApprovers) {
                $application->update([
                    'approved_by' => auth()->id() ?? auth('tenant')->id(),
                    'approved_at' => now(),
                    'approved_amount' => $application->recommended_amount,
                    'approved_term' => $application->recommended_term,
                ]);

                $this->statusGuard->transition(
                    $application,
                    LoanApplication::STATUS_APPROVED,
                    "Approved after {$approvedCount} of {$minApprovers} required approvals."
                );
            }

            return $approval;
        });
    }

    /**
     * Record a rejection vote and immediately transition the application to 'rejected'.
     *
     * @throws ValidationException
     */
    public function decline(LoanApplication $application, string $reason): LoanApplicationApproval
    {
        $this->assertRecommended($application);
        $this->assertNotAlreadyVoted($application);

        return DB::transaction(function () use ($application, $reason) {
            $approval = LoanApplicationApproval::create([
                'loan_application_id' => $application->id,
                'approver_id' => auth()->id() ?? auth('tenant')->id() ?? 1,
                'level' => 1,
                'decision' => 'rejected',
                'comments' => $reason,
                'decided_at' => now(),
            ]);

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

            return $approval;
        });
    }

    /**
     * Resolve the minimum number of approvers from loan_settings for the application's branch.
     * Defaults to 1 if no settings record exists.
     */
    private function minApprovers(LoanApplication $application): int
    {
        $product = $application->loanProduct;
        $approvalSetting = $product?->approvalSetting;

        if ($approvalSetting) {
            $resolved = $approvalSetting->resolveForAmount((float) $application->recommended_amount);

            return (int) $resolved['approval_threshold'];
        }

        return 1;
    }

    /**
     * @throws ValidationException
     */
    private function assertRecommended(LoanApplication $application): void
    {
        if ($application->status !== LoanApplication::STATUS_RECOMMENDED) {
            throw ValidationException::withMessages([
                'status' => [
                    "Application must be in 'recommended' status to record an approval vote (current: '{$application->status}').",
                ],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertNotAlreadyVoted(LoanApplication $application): void
    {
        $actorId = auth()->id() ?? auth('tenant')->id() ?? 1;
        $exists = LoanApplicationApproval::where('loan_application_id', $application->id)
            ->where('approver_id', $actorId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'approver_id' => ['You have already cast a vote on this application.'],
            ]);
        }
    }
}
