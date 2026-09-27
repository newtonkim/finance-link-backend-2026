<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Data\GuarantorRules;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use Illuminate\Validation\ValidationException;

interface LoanGuarantorServiceInterface
{
    /** The guarantor rules that apply to this application (tenant settings + product). */
    public function rules(LoanApplication $application): GuarantorRules;

    /**
     * Pledge a guarantee on an application, or update the amount of an existing
     * pledge by the same guarantor. Every guarantor rule is enforced here.
     *
     * @param  string  $type  LoanApplicationGuarantor::TYPE_INDIVIDUAL or TYPE_GROUP
     * @param  int  $guarantorId  members.id for an individual, savings_groups.id for a group
     *
     * @throws ValidationException
     */
    public function addGuarantor(
        LoanApplication $application,
        string $type,
        int $guarantorId,
        ?int $accountId,
        float $amount,
        ?string $note,
        ?int $actorId
    ): LoanApplicationGuarantor;

    /**
     * Ask the guarantor to accept or decline (again), with a fresh deadline.
     *
     * @throws ValidationException
     */
    public function requestConsent(LoanApplicationGuarantor $pledge, ?int $actorId): LoanApplicationGuarantor;

    /**
     * Record the guarantor's answer, given by the guarantor themselves through the
     * member portal or recorded by staff (optionally with the signed form). Moves
     * an application waiting on guarantors forward once enough have accepted.
     *
     * @param  string  $channel  LoanApplicationGuarantor::CHANNEL_MEMBER_PORTAL or CHANNEL_OFFICER
     *
     * @throws ValidationException
     */
    public function respond(
        LoanApplicationGuarantor $pledge,
        bool $accept,
        ?string $reason,
        string $channel,
        ?int $staffId = null,
        ?string $documentPath = null
    ): LoanApplicationGuarantor;

    /** Expire requests past their deadline, for one application or all. Returns how many. */
    public function expireOverdue(?int $applicationId = null): int;

    /** Ask every guarantor on the application who has not been asked yet. Returns how many. */
    public function requestPending(LoanApplication $application): int;

    /** Move an application out of awaiting_guarantors once its guarantees are adequate. */
    public function advanceIfReady(LoanApplication $application): bool;

    /** @throws ValidationException */
    public function removeGuarantor(LoanApplicationGuarantor $guarantor, ?int $actorId): void;

    /**
     * How much more this guarantor can pledge: their savings times the exposure
     * percentage, less every pledge still standing. Never negative.
     */
    public function freeCapacity(string $type, int $guarantorId, ?int $exceptPledgeId = null): float;

    /**
     * How much of a member's (or group's) savings is held by guarantees that bind
     * them, optionally leaving out those standing behind one loan.
     */
    public function heldAmount(string $type, int $holderId, ?int $exceptLoanId = null): float;

    /** @return array{balance: float, held: float, available: float} */
    public function withdrawable(string $type, int $holderId): array;

    /**
     * Refuse taking $amount out of a guarantor's savings when that would leave less
     * than their guarantees hold. $exceptLoanId lets a guarantor repay the very loan
     * they guarantee from the savings held for it. No-op while holds are switched off.
     *
     * @throws ValidationException
     */
    public function assertCanDebit(string $type, int $holderId, float $amount, ?int $exceptLoanId = null): void;

    /**
     * At disbursement: lock the application's binding guarantees to the loan, and
     * withdraw requests nobody answered. Returns how many were locked.
     */
    public function lockForLoan(LoanApplication $application, Loan $loan): int;

    /** Release every guarantee locked to the loan. Returns how many were released. */
    public function releaseForLoan(Loan $loan, string $reason = 'loan_closed'): int;

    /**
     * Replace a guarantor partway through a loan. The replacement must guarantee at
     * least what is still at stake. With consent required it is asked to accept and
     * the old guarantee stays locked until it does; otherwise it takes over at once.
     *
     * @throws ValidationException
     */
    public function substitute(
        LoanApplicationGuarantor $old,
        string $type,
        int $guarantorId,
        ?int $accountId,
        ?float $amount,
        ?string $note,
        ?int $actorId
    ): LoanApplicationGuarantor;

    /**
     * A guarantor asks to be let go early. Recorded for staff to find a replacement.
     *
     * @throws ValidationException
     */
    public function requestRelease(LoanApplicationGuarantor $pledge, ?string $reason): LoanApplicationGuarantor;

    /** Count, coverage and adequacy of an application's guarantees. */
    public function summary(LoanApplication $application): array;

    /** @throws ValidationException when guarantees fall short */
    public function assertAdequate(LoanApplication $application): void;
}
