<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Member;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use Illuminate\Validation\ValidationException;

class LoanGuarantorService implements LoanGuarantorServiceInterface
{
    /**
     * A guarantor's active commitments may not exceed this multiple of their
     * total savings balance. Configurable in the future via tenant settings.
     */
    private const CAPACITY_MULTIPLIER = 3.0;

    public function addGuarantor(
        LoanApplication $application,
        int $memberId,
        float $guaranteeAmount,
        ?string $notes,
        int $actorId
    ): LoanApplicationGuarantor {
        $this->guardApplicationEditable($application);

        // Member cannot guarantee their own application
        if ($memberId === $application->member_id) {
            throw ValidationException::withMessages([
                'member_id' => ['The applicant cannot be their own guarantor.'],
            ]);
        }

        // Prevent duplicate guarantors on the same application
        $duplicate = $application->guarantors()
            ->where('member_id', $memberId)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'member_id' => ['This member is already a guarantor on this application.'],
            ]);
        }

        $guarantor = Member::findOrFail($memberId);

        // Check capacity before adding
        if (! $this->validateGuarantorCapacity($guarantor)) {
            throw ValidationException::withMessages([
                'member_id' => ['This member has reached their maximum guarantee capacity.'],
            ]);
        }

        return LoanApplicationGuarantor::create([
            'loan_application_id' => $application->id,
            'member_id' => $memberId,
            'guarantee_amount' => $guaranteeAmount,
            'notes' => $notes,
            'created_by' => $actorId,
        ]);
    }

    public function removeGuarantor(LoanApplicationGuarantor $guarantor): void
    {
        $this->guardApplicationEditable($guarantor->loanApplication);
        $guarantor->delete();
    }

    public function validateAdequacy(LoanApplication $application): bool
    {
        $application->loadMissing('loanProduct');
        $required = (int) ($application->loanProduct?->min_guarantors ?? 0);
        $actual = $application->guarantors()->count();

        return $actual >= $required;
    }

    public function validateGuarantorCapacity(Member $member): bool
    {
        $savingsBalance = (float) $member->savingsAccounts()->sum('balance');
        $maxCapacity = $savingsBalance * self::CAPACITY_MULTIPLIER;

        // Total active guarantee commitments on non-cancelled/rejected applications
        $activeCommitment = LoanApplicationGuarantor::where('member_id', $member->id)
            ->whereHas('loanApplication', fn ($q) => $q->whereNotIn('status', ['cancelled', 'rejected']))
            ->sum('guarantee_amount');

        // A member with zero savings can still guarantee if commitment is also zero
        if ($savingsBalance <= 0 && $activeCommitment > 0) {
            return false;
        }

        return (float) $activeCommitment < $maxCapacity;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function guardApplicationEditable(LoanApplication $application): void
    {
        if (! in_array($application->status, ['draft', 'submitted', 'under_review'], true)) {
            throw ValidationException::withMessages([
                'application' => ['Guarantors cannot be modified at this stage.'],
            ]);
        }
    }
}
