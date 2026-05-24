<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Member;
use App\Tenant\Modules\Loans\Data\EligibilityResult;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Settings\Models\LoanSetting;

class TopupEligibilityService
{
    protected LoanEligibilityService $baseEligibilityService;

    public function __construct(LoanEligibilityService $baseEligibilityService)
    {
        $this->baseEligibilityService = $baseEligibilityService;
    }

    /**
     * Evaluate if a loan can be topped up and if the new projected loan is eligible.
     *
     * @param  Loan  $referenceLoan  The existing loan being topped up.
     * @param  float  $freshCashAmount  The new money requested by the member.
     * @param  int  $requestedTerm  The new desired term in months.
     * @param  string  $topupType  'consolidated' or 'parallel'
     */
    public function evaluate(
        Loan $referenceLoan,
        float $freshCashAmount,
        int $requestedTerm,
        string $topupType = 'consolidated'
    ): EligibilityResult {
        $passed = [];
        $failed = [];
        $warnings = [];

        $member = $referenceLoan->member;
        $product = $referenceLoan->loanProduct;
        $branchId = $referenceLoan->branch_id;

        // 1. Fetch settings (Global with Product Overrides)
        $settings = LoanSetting::currentForBranch($branchId);

        $allowTopUp = $product->allow_top_up ?? $settings->allow_top_up;
        $basis = $product->topup_repayment_basis ?? $settings->topup_repayment_basis ?? 'principal_interest';
        $minPercentage = (float) ($product->topup_min_percentage ?? $settings->topup_min_percentage ?? 40.00);

        // 2. Check if top-ups are allowed at all
        if (! $allowTopUp) {
            $failed[] = [
                'key' => 'topup_allowed',
                'label' => 'Top-Ups Allowed',
                'reason' => 'Top-ups are disabled for this loan product or globally.',
            ];

            return new EligibilityResult(
                eligible: false,
                passed: $passed,
                failed: $failed,
                warnings: $warnings,
                maxEligibleAmount: 0
            );
        } else {
            $passed[] = ['key' => 'topup_allowed', 'label' => 'Top-Ups are allowed'];
        }

        // 3. Calculate Paid Percentage Based on configured Basis
        $percentagePaid = $this->calculatePaidPercentage($referenceLoan, $basis);

        if ($percentagePaid >= $minPercentage) {
            $passed[] = [
                'key' => 'topup_threshold',
                'label' => 'Repayment Threshold',
                'message' => sprintf('Member has paid %s%% (Basis: %s), meeting the %s%% minimum requirement.',
                    number_format($percentagePaid, 2),
                    $this->formatBasis($basis),
                    number_format($minPercentage, 2)
                ),
            ];
        } else {
            $failed[] = [
                'key' => 'topup_threshold',
                'label' => 'Repayment Threshold',
                'reason' => sprintf('Member has only paid %s%% (Basis: %s). A minimum of %s%% is required to top up.',
                    number_format($percentagePaid, 2),
                    $this->formatBasis($basis),
                    number_format($minPercentage, 2)
                ),
            ];
        }

        // 4. Calculate Projected Loan Exposure
        // For consolidated: old balance is cleared, new loan = old balance + fresh cash
        // For parallel: old loan remains, new loan = fresh cash
        $projectedNewLoanAmount = $freshCashAmount;
        if ($topupType === 'consolidated') {
            $projectedNewLoanAmount += $referenceLoan->getTotalOutstandingAmount();
        }

        // 5. Run Base Eligibility Check on the projected new loan
        // We temporarily pass the projected total amount to ensure it passes product limits (DSR, Savings Ratio, etc)
        $baseResult = $this->baseEligibilityService->evaluate(
            $member,
            $product,
            $projectedNewLoanAmount,
            $requestedTerm
        );

        // Merge Base Results
        $passed = array_merge($passed, $baseResult->passed);
        $failed = array_merge($failed, $baseResult->failed);
        $warnings = array_merge($warnings, $baseResult->warnings);

        $eligible = empty($failed);

        // Calculate the maximum *fresh cash* they can get
        // Base maxEligibleAmount is the total new exposure they are allowed.
        // So max fresh cash = max total exposure - existing balance (if consolidated)
        $maxFreshCash = $baseResult->maxEligibleAmount;
        if ($topupType === 'consolidated') {
            $maxFreshCash -= $referenceLoan->getTotalOutstandingAmount();
        }

        return new EligibilityResult(
            eligible: $eligible,
            passed: $passed,
            failed: $failed,
            warnings: $warnings,
            maxEligibleAmount: max(0, $maxFreshCash)
        );
    }

    /**
     * Calculates the paid percentage based on the selected basis.
     */
    private function calculatePaidPercentage(Loan $loan, string $basis): float
    {
        $principal = (float) $loan->principal;

        // Sum from schedules
        $schedules = $loan->schedules;
        $principalPaid = $schedules->sum('principal_paid');
        $interestPaid = $schedules->sum('interest_paid');

        $totalExpectedInterest = $schedules->sum('interest_due');

        if ($basis === 'principal_interest') {
            $totalExpected = $principal + $totalExpectedInterest;
            $totalPaid = $principalPaid + $interestPaid;

            if ($totalExpected <= 0) {
                return 0;
            }

            return ($totalPaid / $totalExpected) * 100;
        }

        if ($basis === 'outstanding_balance') {
            // Measure principal reduction: how much of the original principal the member has paid off.
            // Use $loan->outstanding_balance (principal-only column) NOT getTotalOutstandingAmount()
            // which inflates the balance by including accrued interest, charges, and penalties.
            if ($principal <= 0) {
                return 0;
            }
            $remainingPrincipal = max(0, (float) $loan->outstanding_balance);
            $reducedAmount = max(0, $principal - $remainingPrincipal);

            return ($reducedAmount / $principal) * 100;
        }

        // Default: 'principal'
        if ($principal <= 0) {
            return 0;
        }

        return ($principalPaid / $principal) * 100;
    }

    private function formatBasis(string $basis): string
    {
        return match ($basis) {
            'principal_interest' => 'Principal + Interest',
            'outstanding_balance' => 'Outstanding Balance',
            default => 'Principal Only',
        };
    }
}
