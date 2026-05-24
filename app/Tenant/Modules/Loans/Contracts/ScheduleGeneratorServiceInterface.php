<?php

namespace App\Tenant\Modules\Loans\Contracts;

interface ScheduleGeneratorServiceInterface
{
    /**
     * Calculate a full repayment schedule without persisting anything.
     *
     * @return array{
     *   rows: array<int, array{period: int, principal: float, interest: float, installment: float, balance: float}>,
     *   total_interest: float,
     *   installment_amount: float,
     *   messages: string[],
     *   assumptions: string[],
     * }
     */
    public function generate(
        float $principal,
        int $term,
        string $interestMethod,
        string $repaymentStructure,
        float $interestRate,
        string $interestPeriod,
        string $repaymentCycle,
    ): array;
}
