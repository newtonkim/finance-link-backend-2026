<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Contracts\ScheduleGeneratorServiceInterface;

/**
 * Pure financial calculation — no I/O, no DB, no dates.
 *
 * Calculates a repayment schedule for a given principal, term, rate, and
 * interest method.  Both LoanProductPreviewService (UI preview) and
 * LoanDisbursementService (persist to loan_schedules) delegate here so
 * the schedule mathematics live in exactly one place.
 */
class ScheduleGeneratorService implements ScheduleGeneratorServiceInterface
{
    public function generate(
        float $principal,
        int $term,
        string $interestMethod,
        string $repaymentStructure,
        float $interestRate,
        string $interestPeriod,
        string $repaymentCycle,
    ): array {
        $periodRate = $this->periodRate($interestRate, $interestPeriod, $repaymentCycle);

        return match (true) {
            $interestMethod === 'reducing_balance' && $repaymentStructure === 'equal_principal' => $this->equalPrincipal($principal, $term, $periodRate),
            $interestMethod === 'reducing_balance' => $this->equalInstallment($principal, $term, $periodRate),
            default => $this->flat($principal, $term, $periodRate),
        };
    }

    // ─── Calculation methods ──────────────────────────────────────────────────

    private function flat(float $principal, int $term, float $periodRate): array
    {
        $totalInterest = round($principal * $periodRate * $term, 2);
        $installment = round(($principal + $totalInterest) / $term, 2);
        $principalPerInstallment = round($principal / $term, 2);
        $interestPerInstallment = round($totalInterest / $term, 2);
        $balance = $principal;
        $rows = [];

        for ($period = 1; $period <= $term; $period++) {
            $balance = max(0, round($balance - $principalPerInstallment, 2));
            $rows[] = [
                'period' => $period,
                'principal' => $principalPerInstallment,
                'interest' => $interestPerInstallment,
                'installment' => $installment,
                'balance' => $balance,
            ];
        }

        return [
            'rows' => $rows,
            'installment_amount' => $installment,
            'total_interest' => $totalInterest,
            'messages' => ['Flat-rate schedule calculated from original principal.'],
            'assumptions' => ['Interest is spread evenly across the full term.'],
        ];
    }

    private function equalInstallment(float $principal, int $term, float $periodRate): array
    {
        $installment = $periodRate > 0
            ? round(($principal * $periodRate) / (1 - pow(1 + $periodRate, -$term)), 2)
            : round($principal / $term, 2);

        $balance = $principal;
        $totalInterest = 0.0;
        $rows = [];

        for ($period = 1; $period <= $term; $period++) {
            $interest = round($balance * $periodRate, 2);
            $principalComponent = round($installment - $interest, 2);

            if ($period === $term) {
                $principalComponent = round($balance, 2);
                $installment = round($principalComponent + $interest, 2);
            }

            $balance = max(0, round($balance - $principalComponent, 2));
            $totalInterest += $interest;

            $rows[] = [
                'period' => $period,
                'principal' => $principalComponent,
                'interest' => $interest,
                'installment' => $installment,
                'balance' => $balance,
            ];
        }

        return [
            'rows' => $rows,
            'installment_amount' => $installment,
            'total_interest' => round($totalInterest, 2),
            'messages' => ['Reducing-balance equal-installment schedule calculated.'],
            'assumptions' => ['Interest calculated on the declining outstanding balance.'],
        ];
    }

    private function equalPrincipal(float $principal, int $term, float $periodRate): array
    {
        $principalComponent = round($principal / $term, 2);
        $balance = $principal;
        $totalInterest = 0.0;
        $firstInstallment = 0.0;
        $rows = [];

        for ($period = 1; $period <= $term; $period++) {
            $interest = round($balance * $periodRate, 2);
            $installment = round($principalComponent + $interest, 2);

            if ($period === $term) {
                $principalComponent = round($balance, 2);
                $installment = round($principalComponent + $interest, 2);
            }

            $balance = max(0, round($balance - $principalComponent, 2));
            $totalInterest += $interest;

            if ($period === 1) {
                $firstInstallment = $installment;
            }

            $rows[] = [
                'period' => $period,
                'principal' => $principalComponent,
                'interest' => $interest,
                'installment' => $installment,
                'balance' => $balance,
            ];
        }

        return [
            'rows' => $rows,
            'installment_amount' => $firstInstallment,
            'total_interest' => round($totalInterest, 2),
            'messages' => ['Reducing-balance equal-principal schedule calculated.'],
            'assumptions' => ['Principal stays level while the total installment declines over time.'],
        ];
    }

    // ─── Rate conversion ──────────────────────────────────────────────────────

    private function periodRate(float $rate, string $interestPeriod, string $repaymentCycle): float
    {
        $annualRate = match (true) {
            in_array($interestPeriod, ['monthly', 'per_month'], true) => $rate * 12,
            in_array($interestPeriod, ['weekly'], true) => $rate * 52,
            in_array($interestPeriod, ['daily'], true) => $rate * 365,
            in_array($interestPeriod, ['yearly', 'per_year'], true) => $rate,
            default => $rate * 12,
        };

        $repaymentBase = match ($repaymentCycle) {
            'daily' => 365,
            'weekly' => 52,
            'bi-weekly', 'biweekly' => 26,
            'quarterly' => 4,
            'annually', 'annual', 'yearly' => 1,
            default => 12, // monthly
        };

        return round(($annualRate / 100) / $repaymentBase, 8);
    }
}
