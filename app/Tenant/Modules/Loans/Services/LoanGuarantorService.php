<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Member;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Data\GuarantorRules;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Support\TenantMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanGuarantorService implements LoanGuarantorServiceInterface
{
    /** Application statuses in which the guarantor list may still change. */
    private const EDITABLE_STATUSES = [
        LoanApplication::STATUS_DRAFT,
        LoanApplication::STATUS_SUBMITTED,
        LoanApplication::STATUS_UNDER_REVIEW,
        LoanApplication::STATUS_AWAITING_DOCUMENTS,
        LoanApplication::STATUS_AWAITING_GUARANTORS,
        LoanApplication::STATUS_RETURNED_FOR_CORRECTION,
    ];

    /** A pledge on an application in one of these no longer commits the guarantor. */
    private const RELEASED_APPLICATION_STATUSES = [
        LoanApplication::STATUS_REJECTED,
        LoanApplication::STATUS_CANCELLED,
    ];

    /**
     * Guarantees are released only when the loan is closed. A written-off loan keeps
     * them committed, since the SACCO may still recover from its guarantors.
     */
    private const RELEASED_LOAN_STATUSES = ['closed'];

    /** @var array<int, GuarantorRules> */
    private array $rulesCache = [];

    public function rules(LoanApplication $application): GuarantorRules
    {
        $productId = (int) $application->loan_product_id;

        if (! isset($this->rulesCache[$productId])) {
            $application->loadMissing('loanProduct');
            $this->rulesCache[$productId] = GuarantorRules::for($application->loanProduct);
        }

        return $this->rulesCache[$productId];
    }

    public function addGuarantor(
        LoanApplication $application,
        string $type,
        int $guarantorId,
        ?int $accountId,
        float $amount,
        ?string $note,
        ?int $actorId
    ): LoanApplicationGuarantor {
        $this->guardApplicationEditable($application);

        if (! in_array($type, [LoanApplicationGuarantor::TYPE_INDIVIDUAL, LoanApplicationGuarantor::TYPE_GROUP], true)) {
            throw ValidationException::withMessages([
                'guarantor_type' => ['A guarantor must be an individual member or a group.'],
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'guarantee_amount' => ['Enter the amount this guarantor is pledging.'],
            ]);
        }

        $rules = $this->rules($application);
        $name = $type === LoanApplicationGuarantor::TYPE_GROUP
            ? $this->assertGroupMayGuarantee($guarantorId, $accountId)
            : $this->assertMemberMayGuarantee($application, $rules, $guarantorId, $accountId);

        return DB::connection('tenant')->transaction(function () use ($application, $type, $guarantorId, $accountId, $amount, $note, $actorId, $rules, $name) {
            $existing = LoanApplicationGuarantor::withTrashed()
                ->where('loan_application_id', $application->id)
                ->where('guarantor_type', $type)
                ->where('guarantor_id', $guarantorId)
                ->lockForUpdate()
                ->first();

            $isStanding = $existing
                && ! $existing->trashed()
                && in_array($existing->status, LoanApplicationGuarantor::ACTIVE_STATUSES, true);

            if (! $isStanding && $rules->maximum > 0) {
                $count = $application->guarantors()->active()->count();

                if ($count >= $rules->maximum) {
                    throw ValidationException::withMessages([
                        'guarantors' => ["This loan can have at most {$rules->maximum} guarantor(s)."],
                    ]);
                }
            }

            $free = $this->freeCapacity($type, $guarantorId, $existing?->id);

            if ($amount > $free) {
                throw ValidationException::withMessages([
                    'guarantee_amount' => [sprintf(
                        '%s can pledge at most %s more.',
                        $name,
                        TenantMoney::format($free)
                    )],
                ]);
            }

            $guarantor = $existing ?? new LoanApplicationGuarantor([
                'loan_application_id' => $application->id,
                'guarantor_type' => $type,
                'guarantor_id' => $guarantorId,
                'created_by' => $actorId,
            ]);

            if ($guarantor->trashed()) {
                $guarantor->restore();
            }

            if (! $isStanding) {
                $guarantor->status = LoanApplicationGuarantor::STATUS_PROPOSED;
                $guarantor->status_changed_at = now();
            }

            $guarantor->fill([
                'guarantor_account_id' => $accountId ?? $guarantor->guarantor_account_id,
                'guarantee_amount' => $amount,
                'note' => $note ?? $guarantor->note,
                'updated_by' => $actorId,
            ])->save();

            if (! $guarantor->code) {
                $guarantor->forceFill(['code' => sprintf('GRT%06d', $guarantor->id)])->save();
            }

            return $guarantor;
        });
    }

    public function removeGuarantor(LoanApplicationGuarantor $guarantor, ?int $actorId): void
    {
        $this->guardApplicationEditable($guarantor->loanApplication);

        $guarantor->forceFill([
            'status' => LoanApplicationGuarantor::STATUS_WITHDRAWN,
            'status_changed_at' => now(),
            'updated_by' => $actorId,
        ])->save();

        $guarantor->delete();
    }

    public function freeCapacity(string $type, int $guarantorId, ?int $exceptPledgeId = null): float
    {
        $exposure = GuarantorRules::for()->exposurePercentage / 100;
        $capacity = $this->savingsBalance($type, $guarantorId) * $exposure;
        $committed = $this->committedAmount($type, $guarantorId, $exceptPledgeId);

        return round(max(0, $capacity - $committed), 2);
    }

    public function summary(LoanApplication $application): array
    {
        $rules = $this->rules($application);
        $pledges = $application->guarantors()->active()->get(['id', 'guarantee_amount']);

        $count = $pledges->count();
        $pledged = (float) $pledges->sum('guarantee_amount');
        $loanAmount = $this->loanAmount($application);

        // The borrower's own free savings stand behind the loan too, so they count
        // toward coverage alongside what the guarantors pledge.
        $borrowerDeposits = $application->member_id
            ? $this->freeCapacity(LoanApplicationGuarantor::TYPE_INDIVIDUAL, (int) $application->member_id)
            : 0.0;

        $covered = $pledged + $borrowerDeposits;
        $requiredCoverage = round($loanAmount * $rules->coveragePercentage / 100, 2);

        $countMet = ! $rules->required || $count >= $rules->minimum;
        $coverageMet = ! $rules->required || $rules->coveragePercentage <= 0 || $covered >= $requiredCoverage;

        $problems = [];
        if (! $countMet) {
            $problems[] = sprintf(
                'This loan needs at least %d guarantor(s); %d added.',
                $rules->minimum,
                $count
            );
        }
        if (! $coverageMet) {
            $problems[] = sprintf(
                'Guarantees and the borrower\'s free savings cover %s of the %s required (%s%% of the loan).',
                TenantMoney::format($covered),
                TenantMoney::format($requiredCoverage),
                rtrim(rtrim(number_format($rules->coveragePercentage, 2), '0'), '.')
            );
        }

        return [
            'rules' => $rules->toArray(),
            'guarantor_count' => $count,
            'remaining_guarantors' => $rules->required ? max(0, $rules->minimum - $count) : 0,
            'loan_amount' => $loanAmount,
            'pledged_amount' => round($pledged, 2),
            'borrower_free_savings' => $borrowerDeposits,
            'covered_amount' => round($covered, 2),
            'required_coverage_amount' => $requiredCoverage,
            'coverage_shortfall' => $coverageMet ? 0.0 : round($requiredCoverage - $covered, 2),
            'count_met' => $countMet,
            'coverage_met' => $coverageMet,
            'adequate' => $countMet && $coverageMet,
            'problems' => $problems,
        ];
    }

    public function assertAdequate(LoanApplication $application): void
    {
        $summary = $this->summary($application);

        if (! $summary['adequate']) {
            throw ValidationException::withMessages(['guarantors' => $summary['problems']]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function guardApplicationEditable(LoanApplication $application): void
    {
        if (! in_array($application->status, self::EDITABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'application' => ['Guarantors cannot be changed once an application has passed review.'],
            ]);
        }
    }

    /** @return string the member's name, for messages */
    private function assertMemberMayGuarantee(LoanApplication $application, GuarantorRules $rules, int $memberId, ?int $accountId): string
    {
        $member = Member::find($memberId);

        if (! $member) {
            throw ValidationException::withMessages(['guarantor_id' => ['That member does not exist.']]);
        }

        if (! $rules->allowSelfGuarantee && $memberId === (int) $application->member_id) {
            throw ValidationException::withMessages([
                'guarantor_id' => ['The borrower cannot guarantee their own loan.'],
            ]);
        }

        if ($rules->membersOnly && $member->status !== 'active') {
            throw ValidationException::withMessages([
                'guarantor_id' => ["{$member->name} is not an active member, and only active members may guarantee loans."],
            ]);
        }

        if ($accountId !== null) {
            $owns = DB::connection('tenant')->table('savings_accounts')
                ->where('id', $accountId)
                ->where('member_id', $memberId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $owns) {
                throw ValidationException::withMessages([
                    'guarantor_account_id' => ["That savings account does not belong to {$member->name}."],
                ]);
            }
        }

        return $member->name;
    }

    /** @return string the group's name, for messages */
    private function assertGroupMayGuarantee(int $groupId, ?int $accountId): string
    {
        $group = SavingsGroup::find($groupId);

        if (! $group) {
            throw ValidationException::withMessages(['guarantor_id' => ['That group does not exist.']]);
        }

        if ($accountId !== null) {
            $owns = DB::connection('tenant')->table('group_savings_accounts')
                ->where('id', $accountId)
                ->where('savings_group_id', $groupId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $owns) {
                throw ValidationException::withMessages([
                    'guarantor_account_id' => ["That savings account does not belong to {$group->name}."],
                ]);
            }
        }

        return $group->name;
    }

    private function savingsBalance(string $type, int $guarantorId): float
    {
        [$table, $ownerColumn] = $type === LoanApplicationGuarantor::TYPE_GROUP
            ? ['group_savings_accounts', 'savings_group_id']
            : ['savings_accounts', 'member_id'];

        return (float) DB::connection('tenant')->table($table)
            ->where($ownerColumn, $guarantorId)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'closed')
            ->sum('balance');
    }

    /** Everything this guarantor has pledged that has not yet been released. */
    private function committedAmount(string $type, int $guarantorId, ?int $exceptPledgeId): float
    {
        return (float) LoanApplicationGuarantor::active()
            ->where('guarantor_type', $type)
            ->where('guarantor_id', $guarantorId)
            ->when($exceptPledgeId, fn ($q) => $q->whereKeyNot($exceptPledgeId))
            ->whereHas('loanApplication', function ($q) {
                $q->whereNotIn('status', self::RELEASED_APPLICATION_STATUSES)
                    ->whereNotExists(function ($loans) {
                        $loans->select(DB::raw(1))
                            ->from('loans')
                            ->whereColumn('loans.loan_application_id', 'loan_applications.id')
                            ->whereIn('loans.status', self::RELEASED_LOAN_STATUSES);
                    });
            })
            ->sum('guarantee_amount');
    }

    private function loanAmount(LoanApplication $application): float
    {
        return (float) ($application->approved_amount
            ?? $application->recommended_amount
            ?? $application->requested_amount
            ?? 0);
    }
}
