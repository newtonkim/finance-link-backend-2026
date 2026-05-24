<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Models\LoanArrearsTier;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use Illuminate\Support\Collection;

class ArrearsTierPenaltyCalculator
{
    protected ?Collection $activeTiers = null;

    public function loadActiveTiers(): void
    {
        $this->activeTiers = LoanArrearsTier::where('is_active', true)
            ->orderBy('from_day')
            ->get();
    }

    public function findApplicableTier(int $daysOverdue): ?LoanArrearsTier
    {
        if ($this->activeTiers === null) {
            $this->loadActiveTiers();
        }

        if ($this->activeTiers->isEmpty()) {
            return null;
        }

        return $this->activeTiers->first(function (LoanArrearsTier $tier) use ($daysOverdue) {
            if ($tier->to_day === null) {
                return $daysOverdue >= $tier->from_day;
            }

            return $daysOverdue >= $tier->from_day && $daysOverdue <= $tier->to_day;
        });
    }

    /**
     * Calculate the absolute total penalty that should be applied for all tiers passed.
     * This allows for idempotent "snapping" to the correct value.
     */
    public function calculateCumulativePenalty(LoanSchedule $schedule, int $daysOverdue): array
    {
        if ($this->activeTiers === null) {
            $this->loadActiveTiers();
        }

        if ($this->activeTiers->isEmpty()) {
            return ['total' => 0.0, 'last_tier_id' => null];
        }

        // Find all tiers that apply to this number of days (cumulative)
        $applicableTiers = $this->activeTiers->filter(function (LoanArrearsTier $tier) use ($daysOverdue) {
            return $daysOverdue >= $tier->from_day;
        });

        if ($applicableTiers->isEmpty()) {
            return ['total' => 0.0, 'last_tier_id' => null];
        }

        $total = 0.0;
        foreach ($applicableTiers as $tier) {
            $total += $this->computeCharge($schedule, $tier);
        }

        return [
            'total' => round($total, 2),
            'last_tier_id' => $applicableTiers->last()->id,
        ];
    }

    public function computeCharge(LoanSchedule $schedule, LoanArrearsTier $tier): float
    {
        $rate = (float) $tier->charge_value;
        if ($rate <= 0) {
            return 0.0;
        }

        if ($tier->charge_type === 'flat') {
            return round($rate, 2);
        }

        // Percentage based calculation
        $baseAmount = 0.0;
        switch ($tier->applies_to) {
            case 'outstanding_balance':
                // The outstanding balance of the entire loan
                $onBook = (float) $schedule->loan?->outstanding_balance;
                if ($onBook <= 0) {
                    $totalPrincipalDue = (float) $schedule->loan?->schedules()->sum('principal_due');
                    $totalPrincipalPaid = (float) $schedule->loan?->schedules()->sum('principal_paid');
                    $baseAmount = max(0, $totalPrincipalDue - $totalPrincipalPaid);
                } else {
                    $baseAmount = $onBook;
                }
                break;
            case 'principal_due':
                // The outstanding principal of this specific installment
                $baseAmount = max(0, (float) $schedule->principal_due - (float) $schedule->principal_paid);
                break;
            case 'installment_due':
                // Total remaining amount due for this specific installment
                $paid = (float) $schedule->principal_paid + (float) $schedule->interest_paid + (float) $schedule->charges_paid + (float) $schedule->penalty_paid;
                $due = (float) $schedule->total_due + (float) $schedule->penalty_due + (float) $schedule->charges_due; // Adjusting for dynamic amounts
                $baseAmount = max(0, $due - $paid);
                break;
        }

        return round($baseAmount * ($rate / 100), 2);
    }
}
