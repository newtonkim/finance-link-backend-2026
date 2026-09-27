<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Member;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Data\GuarantorRules;
use App\Tenant\Modules\Loans\Models\Loan;
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

    public function __construct(
        protected GuarantorNotifier $notifier,
        protected LoanApplicationStatusGuard $statusGuard,
    ) {}

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

            $previousStatus = $isStanding ? $guarantor->status : null;
            $previousAmount = $isStanding ? (float) $guarantor->guarantee_amount : null;

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

            if ($rules->consentRequired) {
                // Ask again whenever what the guarantor is being asked to stand behind
                // changes: a new pledge, a changed pending one, or a larger accepted one.
                $mustAsk = ! $isStanding
                    || $previousStatus === LoanApplicationGuarantor::STATUS_PROPOSED
                    || ($previousStatus === LoanApplicationGuarantor::STATUS_REQUESTED && $amount != $previousAmount)
                    || ($previousStatus === LoanApplicationGuarantor::STATUS_ACCEPTED && $amount > $previousAmount);

                if ($mustAsk) {
                    $this->markRequested($guarantor, $rules);
                }
            } elseif (! $isStanding) {
                $this->notifier->added($guarantor);
            }

            $this->advanceIfReady($application);

            return $guarantor;
        });
    }

    public function requestConsent(LoanApplicationGuarantor $pledge, ?int $actorId): LoanApplicationGuarantor
    {
        $application = $pledge->loanApplication;
        $this->guardApplicationEditable($application);

        $askable = [
            LoanApplicationGuarantor::STATUS_PROPOSED,
            LoanApplicationGuarantor::STATUS_REQUESTED,
            LoanApplicationGuarantor::STATUS_EXPIRED,
            LoanApplicationGuarantor::STATUS_DECLINED,
        ];

        if (! in_array($pledge->status, $askable, true)) {
            throw ValidationException::withMessages([
                'guarantor' => ['This guarantor has already accepted.'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($pledge, $application, $actorId) {
            // An expired or declined pledge stopped holding the guarantor's capacity,
            // which may have been pledged elsewhere since.
            if (! in_array($pledge->status, LoanApplicationGuarantor::ACTIVE_STATUSES, true)) {
                $this->assertWithinCapacity($pledge);
            }

            $pledge->updated_by = $actorId;
            $this->markRequested($pledge, $this->rules($application));

            return $pledge;
        });
    }

    public function respond(
        LoanApplicationGuarantor $pledge,
        bool $accept,
        ?string $reason,
        string $channel,
        ?int $staffId = null,
        ?string $documentPath = null
    ): LoanApplicationGuarantor {
        $application = $pledge->loanApplication;
        $this->guardApplicationEditable($application);
        $this->expireOverdue($application->id);
        $pledge->refresh();

        $answerable = $channel === LoanApplicationGuarantor::CHANNEL_OFFICER
            // Staff can record an answer given on paper even after the request lapsed.
            ? [...LoanApplicationGuarantor::AWAITING_RESPONSE_STATUSES, LoanApplicationGuarantor::STATUS_EXPIRED]
            : [LoanApplicationGuarantor::STATUS_REQUESTED];

        if (! in_array($pledge->status, $answerable, true)) {
            throw ValidationException::withMessages([
                'guarantor' => [match ($pledge->status) {
                    LoanApplicationGuarantor::STATUS_ACCEPTED => 'This guarantee has already been accepted.',
                    LoanApplicationGuarantor::STATUS_DECLINED => 'This guarantee has already been declined.',
                    LoanApplicationGuarantor::STATUS_EXPIRED => 'This request has expired. Ask the SACCO to send it again.',
                    default => 'This guarantee is not waiting for an answer.',
                }],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($pledge, $application, $accept, $reason, $channel, $staffId, $documentPath) {
            // Until a guarantee binds, the guarantor's savings are not held, so they
            // may have dropped since the request went out.
            if ($accept) {
                $this->assertWithinCapacity($pledge);
            }

            $pledge->forceFill([
                'status' => $accept ? LoanApplicationGuarantor::STATUS_ACCEPTED : LoanApplicationGuarantor::STATUS_DECLINED,
                'status_changed_at' => now(),
                'responded_at' => now(),
                'response_channel' => $channel,
                'responded_by' => $staffId,
                'decline_reason' => $accept ? null : $reason,
                'accepted_date' => $accept ? now()->toDateString() : null,
                'consent_document_path' => $documentPath ?? $pledge->consent_document_path,
            ])->save();

            $this->advanceIfReady($application);

            return $pledge;
        });
    }

    public function expireOverdue(?int $applicationId = null): int
    {
        return LoanApplicationGuarantor::query()
            ->where('status', LoanApplicationGuarantor::STATUS_REQUESTED)
            ->where('consent_expires_at', '<', now())
            ->when($applicationId, fn ($q) => $q->where('loan_application_id', $applicationId))
            ->update([
                'status' => LoanApplicationGuarantor::STATUS_EXPIRED,
                'status_changed_at' => now(),
            ]);
    }

    public function requestPending(LoanApplication $application): int
    {
        $rules = $this->rules($application);
        $pending = $application->guarantors()
            ->where('status', LoanApplicationGuarantor::STATUS_PROPOSED)
            ->get();

        foreach ($pending as $pledge) {
            $this->markRequested($pledge, $rules);
        }

        return $pending->count();
    }

    public function advanceIfReady(LoanApplication $application): bool
    {
        $application->refresh();

        if ($application->status !== LoanApplication::STATUS_AWAITING_GUARANTORS) {
            return false;
        }

        if (! $this->summary($application)['adequate']) {
            return false;
        }

        $this->statusGuard->transition(
            $application,
            LoanApplication::STATUS_SUBMITTED,
            'Enough guarantors have accepted.'
        );
        $application->submitted_at = now();
        $application->save();

        return true;
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

    public function heldAmount(string $type, int $holderId, ?int $exceptLoanId = null): float
    {
        return (float) $this->standingPledges($type, $holderId)
            ->whereIn('status', $this->bindingStatuses(GuarantorRules::for()))
            ->when($exceptLoanId, function ($q) use ($exceptLoanId) {
                $q->where(fn ($q) => $q->whereNull('loan_id')->orWhere('loan_id', '!=', $exceptLoanId));
            })
            ->sum('guarantee_amount');
    }

    public function withdrawable(string $type, int $holderId): array
    {
        $balance = $this->savingsBalance($type, $holderId);
        $held = GuarantorRules::for()->holdSavings ? $this->heldAmount($type, $holderId) : 0.0;

        return [
            'balance' => round($balance, 2),
            'held' => round($held, 2),
            'available' => round(max(0, $balance - $held), 2),
        ];
    }

    public function assertCanDebit(string $type, int $holderId, float $amount, ?int $exceptLoanId = null): void
    {
        if ($amount <= 0 || ! GuarantorRules::for()->holdSavings) {
            return;
        }

        $held = $this->heldAmount($type, $holderId, $exceptLoanId);

        if ($held <= 0) {
            return;
        }

        $available = $this->savingsBalance($type, $holderId) - $held;

        if (round($amount, 2) > round($available, 2)) {
            throw ValidationException::withMessages([
                'amount' => [sprintf(
                    '%s of these savings is held as a guarantee for other members\' loans, so at most %s can be taken out.',
                    TenantMoney::format($held),
                    TenantMoney::format(max(0, $available))
                )],
            ]);
        }
    }

    public function lockForLoan(LoanApplication $application, Loan $loan): int
    {
        $binding = $this->bindingStatuses($this->rules($application));
        $locked = 0;

        foreach ($application->guarantors()->get() as $pledge) {
            if (in_array($pledge->status, $binding, true)) {
                $pledge->forceFill([
                    'status' => LoanApplicationGuarantor::STATUS_LOCKED,
                    'status_changed_at' => now(),
                    'loan_id' => $loan->id,
                    'locked_at' => now(),
                ])->save();
                $locked++;
            } elseif (in_array($pledge->status, LoanApplicationGuarantor::AWAITING_RESPONSE_STATUSES, true)) {
                // The loan went ahead without this guarantor's answer, so the request
                // should stop reserving their capacity.
                $pledge->forceFill([
                    'status' => LoanApplicationGuarantor::STATUS_WITHDRAWN,
                    'status_changed_at' => now(),
                    'released_at' => now(),
                    'release_reason' => 'unanswered_at_disbursement',
                ])->save();
            }
        }

        return $locked;
    }

    public function releaseForLoan(Loan $loan, string $reason = 'loan_closed'): int
    {
        return LoanApplicationGuarantor::query()
            ->where('status', LoanApplicationGuarantor::STATUS_LOCKED)
            ->where(function ($q) use ($loan) {
                $q->where('loan_id', $loan->id);
                if ($loan->loan_application_id) {
                    $q->orWhere('loan_application_id', $loan->loan_application_id);
                }
            })
            ->update([
                'status' => LoanApplicationGuarantor::STATUS_RELEASED,
                'status_changed_at' => now(),
                'released_at' => now(),
                'released_date' => now()->toDateString(),
                'release_reason' => $reason,
            ]);
    }

    public function summary(LoanApplication $application): array
    {
        $this->expireOverdue($application->id);

        $rules = $this->rules($application);
        $pledges = $application->guarantors()->get(['id', 'status', 'guarantee_amount']);
        $byStatus = fn (array $statuses) => $pledges->whereIn('status', $statuses);

        // With consent on, only accepted pledges count; the ones still waiting for an
        // answer are reported separately so the screen can say what is outstanding.
        $counted = $rules->consentRequired
            ? $byStatus([LoanApplicationGuarantor::STATUS_ACCEPTED])
            : $byStatus(LoanApplicationGuarantor::ACTIVE_STATUSES);
        $pending = $rules->consentRequired
            ? $byStatus(LoanApplicationGuarantor::AWAITING_RESPONSE_STATUSES)
            : collect();

        $loanAmount = $this->loanAmount($application);

        // The borrower's own free savings stand behind the loan too, so they count
        // toward coverage alongside what the guarantors pledge.
        $borrowerDeposits = $application->member_id
            ? $this->freeCapacity(LoanApplicationGuarantor::TYPE_INDIVIDUAL, (int) $application->member_id)
            : 0.0;

        $requiredCoverage = round($loanAmount * $rules->coveragePercentage / 100, 2);

        $count = $counted->count();
        $pledged = (float) $counted->sum('guarantee_amount');
        $covered = $pledged + $borrowerDeposits;

        $countMet = $this->countMet($rules, $count);
        $coverageMet = $this->coverageMet($rules, $covered, $requiredCoverage);

        $pendingAmount = (float) $pending->sum('guarantee_amount');
        $adequateIfPendingAccept = $this->countMet($rules, $count + $pending->count())
            && $this->coverageMet($rules, $covered + $pendingAmount, $requiredCoverage);

        $problems = [];
        if (! $countMet) {
            $problems[] = sprintf(
                $rules->consentRequired
                    ? 'This loan needs at least %d accepted guarantor(s); %d accepted.'
                    : 'This loan needs at least %d guarantor(s); %d added.',
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
        if ($problems && $pending->isNotEmpty()) {
            $problems[] = sprintf('%d guarantor(s) have not answered yet.', $pending->count());
        }

        return [
            'rules' => $rules->toArray(),
            'guarantor_count' => $count,
            'remaining_guarantors' => $rules->required ? max(0, $rules->minimum - $count) : 0,
            'pending_count' => $pending->count(),
            'pending_amount' => round($pendingAmount, 2),
            'declined_count' => $byStatus([LoanApplicationGuarantor::STATUS_DECLINED])->count(),
            'expired_count' => $byStatus([LoanApplicationGuarantor::STATUS_EXPIRED])->count(),
            'loan_amount' => $loanAmount,
            'pledged_amount' => round($pledged, 2),
            'borrower_free_savings' => $borrowerDeposits,
            'covered_amount' => round($covered, 2),
            'required_coverage_amount' => $requiredCoverage,
            'coverage_shortfall' => $coverageMet ? 0.0 : round($requiredCoverage - $covered, 2),
            'count_met' => $countMet,
            'coverage_met' => $coverageMet,
            'adequate' => $countMet && $coverageMet,
            'adequate_if_pending_accept' => $adequateIfPendingAccept,
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

    private function countMet(GuarantorRules $rules, int $count): bool
    {
        return ! $rules->required || $count >= $rules->minimum;
    }

    private function coverageMet(GuarantorRules $rules, float $covered, float $requiredCoverage): bool
    {
        return ! $rules->required || $rules->coveragePercentage <= 0 || $covered >= $requiredCoverage;
    }

    /** Puts the pledge in requested with a fresh deadline and asks the guarantor. */
    private function markRequested(LoanApplicationGuarantor $pledge, GuarantorRules $rules): void
    {
        $pledge->forceFill([
            'status' => LoanApplicationGuarantor::STATUS_REQUESTED,
            'status_changed_at' => now(),
            'requested_at' => now(),
            'consent_expires_at' => now()->addDays($rules->consentExpiryDays),
            'responded_at' => null,
            'response_channel' => null,
            'responded_by' => null,
            'decline_reason' => null,
        ])->save();

        $this->notifier->consentRequested($pledge);
    }

    private function assertWithinCapacity(LoanApplicationGuarantor $pledge): void
    {
        $free = $this->freeCapacity($pledge->guarantor_type, $pledge->guarantor_id, $pledge->id);

        if ((float) $pledge->guarantee_amount > $free) {
            throw ValidationException::withMessages([
                'guarantee_amount' => [sprintf(
                    '%s can now pledge at most %s. Update the amount before asking again.',
                    $pledge->guarantorName() ?? 'This guarantor',
                    TenantMoney::format($free)
                )],
            ]);
        }
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
        return (float) $this->standingPledges($type, $guarantorId)
            ->whereIn('status', LoanApplicationGuarantor::ACTIVE_STATUSES)
            ->when($exceptPledgeId, fn ($q) => $q->whereKeyNot($exceptPledgeId))
            ->sum('guarantee_amount');
    }

    /** A guarantor's pledges on applications that are still live or loans not yet closed. */
    private function standingPledges(string $type, int $guarantorId)
    {
        return LoanApplicationGuarantor::query()
            ->where('guarantor_type', $type)
            ->where('guarantor_id', $guarantorId)
            ->whereHas('loanApplication', function ($q) {
                $q->whereNotIn('status', self::RELEASED_APPLICATION_STATUSES)
                    ->whereNotExists(function ($loans) {
                        $loans->select(DB::raw(1))
                            ->from('loans')
                            ->whereColumn('loans.loan_application_id', 'loan_applications.id')
                            ->whereIn('loans.status', self::RELEASED_LOAN_STATUSES);
                    });
            });
    }

    /**
     * Statuses in which a guarantee binds the guarantor, so their savings are held:
     * accepted or locked, or also still-unanswered ones while guarantors do not have
     * to accept (there is nothing for them to answer).
     */
    private function bindingStatuses(GuarantorRules $rules): array
    {
        return $rules->consentRequired
            ? [LoanApplicationGuarantor::STATUS_ACCEPTED, LoanApplicationGuarantor::STATUS_LOCKED]
            : LoanApplicationGuarantor::ACTIVE_STATUSES;
    }

    private function loanAmount(LoanApplication $application): float
    {
        return (float) ($application->approved_amount
            ?? $application->recommended_amount
            ?? $application->requested_amount
            ?? 0);
    }
}
