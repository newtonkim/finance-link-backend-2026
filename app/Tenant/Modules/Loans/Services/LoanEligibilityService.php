<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Member;
use App\Tenant\Modules\Loans\Contracts\LoanEligibilityServiceInterface;
use App\Tenant\Modules\Loans\Data\EligibilityResult;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Models\LoanSchedule;

class LoanEligibilityService implements LoanEligibilityServiceInterface
{
    public function evaluate(
        Member $member,
        LoanProduct $product,
        float $amount,
        int $term,
    ): EligibilityResult {
        $passed = [];
        $failed = [];
        $warnings = [];

        // ── 1. Member is active ──────────────────────────────────────────────
        if ($member->status === 'active') {
            $passed[] = ['key' => 'member_active', 'label' => 'Member is active'];
        } else {
            $failed[] = [
                'key' => 'member_active',
                'label' => 'Member is active',
                'reason' => "Member status is '{$member->status}'. Only active members may apply.",
            ];
        }

        // ── 2. Membership age ────────────────────────────────────────────────
        $minMonths = (int) ($product->min_membership_months ?? 0);
        if ($minMonths > 0 && $member->joined_at) {
            $monthsAsMember = (int) $member->joined_at->diffInMonths(now());
            if ($monthsAsMember >= $minMonths) {
                $passed[] = ['key' => 'membership_age', 'label' => 'Membership age requirement met'];
            } else {
                $failed[] = [
                    'key' => 'membership_age',
                    'label' => 'Membership age',
                    'reason' => "Requires {$minMonths} months of membership; member has {$monthsAsMember}.",
                ];
            }
        } else {
            $passed[] = ['key' => 'membership_age', 'label' => 'No minimum membership age required'];
        }

        // ── 3. Savings balance threshold ─────────────────────────────────────
        $threshold = (float) ($product->savings_appraisal_threshold ?? 0);
        $totalSavings = (float) $member->savingsAccounts()->sum('balance');
        $requiredSavings = $threshold > 0 ? ($amount * $threshold / 100) : 0;

        if ($threshold <= 0 || $totalSavings >= $requiredSavings) {
            $passed[] = ['key' => 'savings_threshold', 'label' => 'Savings balance meets threshold'];
        } else {
            $failed[] = [
                'key' => 'savings_threshold',
                'label' => 'Savings balance threshold',
                'reason' => sprintf(
                    'Savings of %s is below the required %s%% of requested amount (%s).',
                    number_format($totalSavings, 2),
                    $threshold,
                    number_format($requiredSavings, 2)
                ),
            ];
        }

        // ── 4. Share capital ─────────────────────────────────────────────────
        $shareCapital = (float) $member->shares()->sum('total_value');
        if ($shareCapital > 0) {
            $passed[] = ['key' => 'share_capital', 'label' => 'Share capital is paid up'];
        } else {
            $warnings[] = [
                'key' => 'share_capital',
                'label' => 'Share capital',
                'message' => 'Member has no recorded share capital.',
            ];
        }

        // ── 5. Arrears check ─────────────────────────────────────────────────
        $arrearsAmount = $this->totalArrearsAmount($member->id);
        $arrearsAction = $product->arrears_action ?? 'warn';

        if ($arrearsAmount <= 0) {
            $passed[] = ['key' => 'no_arrears', 'label' => 'No outstanding arrears'];
        } elseif ($arrearsAction === 'block') {
            $failed[] = [
                'key' => 'no_arrears',
                'label' => 'No outstanding arrears',
                'reason' => sprintf(
                    'Member has arrears of %s. This product blocks applications with unresolved arrears.',
                    number_format($arrearsAmount, 2)
                ),
            ];
        } else {
            $warnings[] = [
                'key' => 'no_arrears',
                'label' => 'Arrears',
                'message' => sprintf(
                    'Member has arrears of %s. This is flagged but does not block this application.',
                    number_format($arrearsAmount, 2)
                ),
            ];
        }

        // ── 6. Amount range ───────────────────────────────────────────────────
        $minAmount = (float) $product->min_amount;
        $maxAmount = (float) $product->max_amount;

        if ($amount >= $minAmount && $amount <= $maxAmount) {
            $passed[] = ['key' => 'amount_range', 'label' => 'Requested amount is within product range'];
        } else {
            $reason = $amount < $minAmount
                ? sprintf('Minimum loan amount is %s.', number_format($minAmount, 2))
                : sprintf('Maximum loan amount is %s.', number_format($maxAmount, 2));

            $failed[] = ['key' => 'amount_range', 'label' => 'Amount within product range', 'reason' => $reason];
        }

        // ── 7. Term limit ─────────────────────────────────────────────────────
        $maxTerm = (int) $product->loan_duration;
        if ($term <= $maxTerm) {
            $passed[] = ['key' => 'term_limit', 'label' => 'Requested term is within product limit'];
        } else {
            $failed[] = [
                'key' => 'term_limit',
                'label' => 'Term within product limit',
                'reason' => "Maximum term is {$maxTerm} months; requested {$term}.",
            ];
        }

        // ── 8. Exposure limit ─────────────────────────────────────────────────
        $exposureLimit = $product->exposure_limit ? (float) $product->exposure_limit : null;
        $activeExposure = $this->activeExposure($member->id);
        $projectedTotal = $activeExposure + $amount;

        if ($exposureLimit === null) {
            $passed[] = ['key' => 'exposure_limit', 'label' => 'No exposure limit configured'];
        } elseif ($projectedTotal <= $exposureLimit) {
            $passed[] = ['key' => 'exposure_limit', 'label' => 'Loan exposure within product limit'];
        } else {
            $failed[] = [
                'key' => 'exposure_limit',
                'label' => 'Loan exposure limit',
                'reason' => sprintf(
                    'Total exposure would be %s, exceeding the product limit of %s.',
                    number_format($projectedTotal, 2),
                    number_format($exposureLimit, 2)
                ),
            ];
        }

        // ── Compute max eligible amount ───────────────────────────────────────
        $maxEligibleAmount = $this->computeMaxEligibleAmount(
            $product,
            $totalSavings,
            $activeExposure,
            $exposureLimit
        );

        $eligible = empty($failed);

        return new EligibilityResult(
            eligible: $eligible,
            passed: $passed,
            failed: $failed,
            warnings: $warnings,
            maxEligibleAmount: $maxEligibleAmount,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function totalArrearsAmount(int $memberId): float
    {
        return (float) LoanSchedule::whereHas('loan', function ($q) use ($memberId) {
            $q->where('member_id', $memberId)
                ->whereIn('status', [LoanStatus::Disbursed, LoanStatus::Active, LoanStatus::Arrears]);
        })
            ->where('due_date', '<', now()->toDateString())
            ->where('status', '!=', 'paid')
            ->selectRaw('SUM(total_due - principal_paid - interest_paid) as arrears')
            ->value('arrears') ?? 0;
    }

    private function activeExposure(int $memberId): float
    {
        return (float) Loan::where('member_id', $memberId)
            ->whereIn('status', [LoanStatus::Disbursed, LoanStatus::Active, LoanStatus::Arrears])
            ->sum('outstanding_balance');
    }

    private function computeMaxEligibleAmount(
        LoanProduct $product,
        float $totalSavings,
        float $activeExposure,
        ?float $exposureLimit
    ): float {
        $cap = (float) $product->max_amount;

        // Savings threshold cap
        $threshold = (float) ($product->savings_appraisal_threshold ?? 0);
        if ($threshold > 0 && $totalSavings > 0) {
            $savingsCap = ($totalSavings / $threshold) * 100;
            $cap = min($cap, $savingsCap);
        }

        // Exposure cap
        if ($exposureLimit !== null && $exposureLimit > $activeExposure) {
            $cap = min($cap, $exposureLimit - $activeExposure);
        } elseif ($exposureLimit !== null) {
            $cap = 0;
        }

        return max(0, $cap);
    }
}
