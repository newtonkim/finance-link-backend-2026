<?php

namespace App\Tenant\Modules\Charges\Contracts;

use App\Tenant\Modules\Members\Models\MemberCharge;

interface ChargeApplicationServiceInterface
{
    /**
     * Calculate the applicable charge, record a MemberCharge, post the JE.
     * Returns null if no charge applies. Idempotent on non-null transactionId.
     */
    public function applyForSavingsEvent(
        int $savingsAccountId,
        string $eventType,
        float $transactionAmount,
        ?int $transactionId,
        int $actorId,
    ): ?MemberCharge;
}
