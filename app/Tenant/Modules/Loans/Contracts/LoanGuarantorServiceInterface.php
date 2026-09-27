<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Data\GuarantorRules;
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

    /** Count, coverage and adequacy of an application's guarantees. */
    public function summary(LoanApplication $application): array;

    /** @throws ValidationException when guarantees fall short */
    public function assertAdequate(LoanApplication $application): void;
}
