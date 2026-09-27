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
