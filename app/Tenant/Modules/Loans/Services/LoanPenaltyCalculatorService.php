<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Services\GlPostingEngine;
use App\Tenant\Modules\Accounting\Services\JournalSequenceService;
use App\Tenant\Modules\Loans\Contracts\LoanPenaltyCalculatorServiceInterface;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LoanPenaltyCalculatorService implements LoanPenaltyCalculatorServiceInterface
{
    public function __construct(
        protected LoanStatusGuard $statusGuard,
        protected ArrearsTierPenaltyCalculator $tierCalculator,
        protected JournalSequenceService $sequence,
        protected GlPostingEngine $gl,
    ) {}

    public function assessAll(): array
    {
        $assessed = 0;
        $skipped = 0;

        $this->tierCalculator->loadActiveTiers();

        LoanSchedule::with('loan.loanProduct')
            ->whereIn('status', ['pending', 'partial', 'arrears'])
            ->where('due_date', '<', now()->toDateString())
            ->chunk(200, function ($schedules) use (&$assessed, &$skipped) {
                foreach ($schedules as $schedule) {
                    $product = $schedule->loan?->loanProduct;
                    if (! $product) {
                        $skipped++;

                        continue;
                    }

                    $graceDays = (int) ($product->penalty_grace_days ?? 0);
                    $daysOverdue = (int) Carbon::parse($schedule->due_date)->startOfDay()->diffInDays(now()->startOfDay());

                    if ($daysOverdue <= $graceDays) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $this->applyPenaltyToSchedule($schedule, $product, $daysOverdue);
                        $assessed++;
                    } catch (\Throwable) {
                        $skipped++;
                    }
                }
            });

        return ['assessed' => $assessed, 'skipped' => $skipped];
    }

    public function assessSchedule(int $scheduleId): void
    {
        $this->tierCalculator->loadActiveTiers();

        $schedule = LoanSchedule::with('loan.loanProduct')->findOrFail($scheduleId);
        $this->processSchedule($schedule);
    }

    public function assessLoan(Loan $loan): void
    {
        $this->tierCalculator->loadActiveTiers();

        $schedules = LoanSchedule::with('loan.loanProduct.penaltyRules')
            ->where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'partial', 'arrears'])
            ->where('due_date', '<', now()->toDateString())
            ->get();

        foreach ($schedules as $schedule) {
            $this->processSchedule($schedule);
        }
    }

    private function processSchedule(LoanSchedule $schedule): void
    {
        $product = $schedule->loan?->loanProduct;

        if (! $product) {
            return;
        }

        $daysOverdue = (int) Carbon::parse($schedule->due_date)->startOfDay()->diffInDays(now()->startOfDay());
        $graceDays = (int) ($product->penalty_grace_days ?? 0);

        if ($daysOverdue <= $graceDays) {
            return;
        }

        $this->applyPenaltyToSchedule($schedule, $product, $daysOverdue);
    }

    // ─── Core penalty logic ───────────────────────────────────────────────────

    private function applyPenaltyToSchedule(LoanSchedule $schedule, $product, int $daysOverdue): void
    {
        DB::connection('tenant')->transaction(function () use ($schedule, $product, $daysOverdue) {
            $isAlreadyArrears = $schedule->status === 'arrears';
            $oldPenaltyDue = (float) $schedule->penalty_due;

            // 1. Calculate the ABSOLUTE target penalty using the tier system
            $cumulative = $this->tierCalculator->calculateCumulativePenalty($schedule, $daysOverdue);
            $targetPenalty = $cumulative['total'];
            $latestTierId = $cumulative['last_tier_id'];

            $isTierActive = ! is_null($latestTierId);
            $penaltyAmount = 0.0;

            if ($isTierActive) {
                // TIER SYSTEM IS ACTIVE: Snap to absolute target
                $schedule->penalty_due = $targetPenalty;
                $schedule->last_arrears_tier_id = $latestTierId;
            } else {
                // Check for product-specific Penalty Rules
                $rulePenalty = $this->calculateRulePenalty($schedule, $product, $daysOverdue);

                if ($rulePenalty > 0) {
                    // RULES FOUND: Apply rule-based penalty
                    $schedule->penalty_due = round($oldPenaltyDue + $rulePenalty, 2);
                } elseif (! $isAlreadyArrears || (float) $schedule->penalty_due <= 0) {
                    // LEGACY FALLBACK: Only if no tiers/rules exist OR it's the first time it's being penalized
                    $penaltyAmount = $this->calculatePenalty($schedule, $product, $daysOverdue);
                    $schedule->penalty_due = round($oldPenaltyDue + $penaltyAmount, 2);
                }
            }

            // Keep total_due in sync with all due components so the payment modal
            // shows the correct full amount (including penalty) to the borrower.
            $schedule->total_due = round(
                (float) $schedule->principal_due
                + (float) $schedule->interest_due
                + (float) $schedule->charges_due
                + (float) $schedule->penalty_due,
                2
            );

            // Only override status to 'arrears' for installments that have not yet
            // been paid. A fully-paid installment must never revert to arrears just
            // because the penalty calculator runs again.
            if ($schedule->status !== 'paid') {
                $schedule->status = 'arrears';
            }

            // Only save if the state changed
            $hasChanged = (float) $schedule->penalty_due !== $oldPenaltyDue
                || ! $isAlreadyArrears
                || $schedule->getOriginal('last_arrears_tier_id') !== $schedule->last_arrears_tier_id;

            if ($hasChanged) {
                $schedule->save();

                // 2. Post accounting entries for the DIFFERENCE
                // This ensures we don't double-post JEs if the user refreshes
                $accrualAmount = round((float) $schedule->penalty_due - $oldPenaltyDue, 2);

                if ($accrualAmount > 0 && $product->penalty_receivable_account_id && $product->penalty_income_account_id) {
                    $this->postPenaltyAccrual($schedule, $product, $accrualAmount);
                }
            }

            // 3. Always flag the loan as arrears if transitionable
            $loan = $schedule->loan;
            if ($loan && $this->statusGuard->canTransition($loan, LoanStatus::Arrears)) {
                $this->statusGuard->transition($loan, LoanStatus::Arrears, "Overdue installment #{$schedule->installment_no}");
            }
        });
    }

    /**
     * Calculate penalty based on product-specific LoanPenaltyRules.
     */
    private function calculateRulePenalty(LoanSchedule $schedule, LoanProduct $product, int $daysOverdue): float
    {
        $rules = $product->penaltyRules;
        if ($rules->isEmpty()) {
            return 0.0;
        }

        $totalPenalty = 0.0;

        foreach ($rules as $rule) {
            $graceDays = (int) ($rule->grace_days ?? 0);
            if ($daysOverdue <= $graceDays) {
                continue;
            }

            $type = (string) ($rule->penalty_type ?? 'flat');
            $rate = (float) ($rule->penalty_rate ?? 0);
            $amount = (float) ($rule->amount ?? 0);

            $baseAmount = 0.0;
            switch ($rule->applies_to) {
                case 'outstanding_balance':
                    $baseAmount = (float) ($schedule->loan->outstanding_balance ?? 0);
                    break;
                case 'principal_due':
                    $baseAmount = (float) $schedule->principal_due;
                    break;
                case 'installment_due':
                default:
                    $baseAmount = (float) $schedule->total_due;
                    break;
            }

            if ($type === 'percentage_per_day') {
                $totalPenalty += round($baseAmount * ($rate / 100) * ($daysOverdue - $graceDays), 2);
            } elseif ($type === 'percentage') {
                $totalPenalty += round($baseAmount * ($rate / 100), 2);
            } else {
                $totalPenalty += round($amount ?: $rate, 2); // flat
            }
        }

        return $totalPenalty;
    }

    private function calculatePenalty(LoanSchedule $schedule, $product, int $daysOverdue): float
    {
        $type = (string) ($product->penalty_type ?? 'flat');
        $rate = (float) ($product->penalty_rate ?? 0);

        if ($rate <= 0) {
            return 0.0;
        }

        $outstandingPrincipal = max(0, (float) $schedule->principal_due - (float) $schedule->principal_paid);

        return match ($type) {
            'percentage_per_day' => round($outstandingPrincipal * ($rate / 100) * $daysOverdue, 2),
            'percentage' => round($outstandingPrincipal * ($rate / 100), 2),
            default => round($rate, 2), // flat
        };
    }

    // ─── Accounting ───────────────────────────────────────────────────────────

    private function postPenaltyAccrual(LoanSchedule $schedule, $product, float $penaltyAmount): void
    {
        $loan = $schedule->loan;
        $narration = "Penalty accrual – {$loan->loan_no} installment #{$schedule->installment_no}";
        $date = now();

        $lines = [
            ['accountId' => $product->penalty_receivable_account_id, 'debit' => $penaltyAmount, 'credit' => 0.0],
            ['accountId' => $product->penalty_income_account_id, 'debit' => 0.0, 'credit' => $penaltyAmount],
        ];

        $je = JournalEntry::create([
            'entry_no' => $this->sequence->nextEntryNo('LOAN_PEN'),
            'date' => $date,
            'period_date' => $date,
            'fiscal_period' => $date->format('Y-m'),
            'journal_type' => 'loan',
            'reference' => $loan->loan_no,
            'reference_type' => 'loan',
            'narration' => $narration,
            'status' => 'posted',
            'is_system' => true,
            'posted_by' => null, // System-generated, null avoids staff FK violation
            'posted_at' => now(),
            'branch_id' => $loan->branch_id,
        ]);

        foreach ($lines as $lineNo => $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $je->id,
                'account_id' => $line['accountId'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'narration' => $narration,
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'branch_id' => $loan->branch_id,
                'line_no' => $lineNo + 1,
            ]);

            $account = ChartOfAccount::on('tenant')->find($line['accountId']);
            $normalBalance = $account?->normal_balance ?? 'DR';
            $this->gl->postToGeneralLedger($je->id, $line['accountId'], $line['debit'], $line['credit'], $date, $narration, $normalBalance);
            $this->gl->postToSubLedger($je->id, $line['accountId'], $loan->id, Loan::class, $line['debit'], $line['credit'], $date, $narration, $normalBalance);
        }
    }
}
