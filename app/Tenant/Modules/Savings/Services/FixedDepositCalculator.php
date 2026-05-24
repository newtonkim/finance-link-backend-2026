<?php

namespace App\Tenant\Modules\Savings\Services;

use Carbon\CarbonInterface;

class FixedDepositCalculator
{
    /**
     * Simple interest: principal × annual_rate × (days / 365)
     * Used for at_maturity and periodic_payout types.
     * For compound, pass the current running balance as $principal.
     */
    public function calculateInterest(
        float $principal,
        float $annualRate,
        CarbonInterface $from,
        CarbonInterface $to,
    ): float {
        $days = (int) $from->diffInDays($to);

        if ($days <= 0 || $principal <= 0 || $annualRate <= 0) {
            return 0.0;
        }

        return round($principal * $annualRate * ($days / 365), 2);
    }

    /**
     * Advance a date by one posting frequency period.
     */
    public function nextInterestDate(CarbonInterface $from, string $frequency): CarbonInterface
    {
        return match ($frequency) {
            'quarterly' => $from->copy()->addMonths(3),
            'semi_annually' => $from->copy()->addMonths(6),
            'annually' => $from->copy()->addYear(),
            default => $from->copy()->addMonth(), // monthly
        };
    }
}
