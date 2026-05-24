<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Accounting\GlCodes;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Services\GlPostingEngine;
use App\Tenant\Modules\Accounting\Services\JournalSequenceService;
use App\Tenant\Modules\Loans\Contracts\LoanDisbursementServiceInterface;
use App\Tenant\Modules\Loans\Contracts\ScheduleGeneratorServiceInterface;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanAppliedCharge;
use App\Tenant\Modules\Loans\Models\LoanCharge;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Settings\Models\LoanSetting;
use App\Tenant\Modules\Settings\Services\HolidayService;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanDisbursementService implements LoanDisbursementServiceInterface
{
    // Required GL mappings on the loan product — all must be present.
    private const REQUIRED_GL_FIELDS = [
        'loan_portfolio_account_id',
        'interest_income_account_id',
        'interest_receivable_account_id',
        'penalty_income_account_id',
        'penalty_receivable_account_id',
        'disbursement_account_id',
    ];

    public function __construct(
        protected LoanApplicationStatusGuard $statusGuard,
        protected ScheduleGeneratorServiceInterface $scheduleGenerator,
        protected HolidayService $holidayService,
        protected JournalSequenceService $sequence,
        protected GlPostingEngine $gl,
    ) {}

    // ─── Public entry point ───────────────────────────────────────────────────

    public function disburse(LoanApplication $application, array $data, ?int $actorId): Loan
    {
        return DB::connection('tenant')->transaction(function () use ($application, $data, $actorId) {
            // Step 1 — Pre-flight checks
            $this->assertDisbursable($application);

            $product = $application->loanProduct()
                ->with([
                    'portfolioAccount',
                    'interestIncomeAccount',
                    'interestReceivableAccount',
                    'disbursementAccount',
                    'chargesIncomeAccount',
                    'chargesReceivableAccount',
                ])
                ->firstOrFail();

            $this->assertGlMappingComplete($product);

            // Step 2 — Calculate charges, deduction mode, and net disbursement
            $principal = (float) $application->approved_amount;
            $processingFee = $this->calculateProcessingFee($product, $principal);
            $disbursedAt = Carbon::parse($data['disbursement_date'] ?? now());
            $scheduleDate = isset($data['schedule_date']) ? Carbon::parse($data['schedule_date']) : $disbursedAt;

            // Sum on_disbursement charges
            $chargesTotal = $this->sumOnDisbursementCharges($application);
            $chargeDeductionMode = $this->resolveChargeDeductionMode($application, $data['charge_deduction_mode'] ?? null);
            $warnings = [];

            // Apply charge deduction mode to compute net disbursement
            $chargeResult = $this->applyChargeDeductionMode(
                $chargeDeductionMode,
                $principal,
                $processingFee,
                $chargesTotal,
                $application,
                $warnings,
            );

            $netDisbursed = $chargeResult['net_disbursed'];
            $effectivePrincipal = $chargeResult['effective_principal'];
            $chargeDeductionMode = $chargeResult['mode']; // may have fallen back
            $chargeReceiptNo = $chargeResult['receipt_no'];

            // Step 3 — Transition: approved → disbursement_pending
            $this->statusGuard->transition(
                $application,
                LoanApplication::STATUS_DISBURSEMENT_PENDING,
                'Disbursement initiated.',
            );

            // Step 4 — Create the Loan record
            $loan = $this->createLoan(
                $application, $product, $data, $actorId,
                $processingFee, $chargesTotal, $chargeDeductionMode,
                $chargeReceiptNo, $netDisbursed, $effectivePrincipal, $disbursedAt, $scheduleDate,
            );

            // Step 4b — Persist applied charges (creates loan_applied_charges rows so
            // processChargesAtDisbursement and the loan account charges tab can query them)
            $this->persistAppliedCharges($loan, $product, $processingFee, $principal);

            // Step 5 — Generate repayment schedule (on effective principal, which may include capitalized charges)
            $totalInterest = $this->generateSchedule($loan, $product, $scheduleDate);

            // Step 6 — Post compound disbursement journal entry
            // Capture the JE so the savings channel can link its subledger entry to it.
            $disbursementJe = $this->postDisbursementEntry(
                $loan, $product, $processingFee, $chargesTotal,
                $chargeDeductionMode, $data, $actorId,
            );

            // Step 6b — For flat-rate products, accrue the full interest upfront
            if ($product->interest_method === 'flat' && $totalInterest > 0) {
                $this->postInterestAccrualEntry($loan, $product, $totalInterest, $actorId);
            }

            // Step 6c — Populate charges_due on schedules and post charge accrual
            // (only for on_repayment charges; on_disbursement charges are handled above)
            $this->processChargesAtDisbursement($loan, $product, $actorId, $chargeDeductionMode);

            // Step 6d — Execute disbursement channel (credit savings, etc.)
            // Pass the disbursement JE so disburseToSavings() can anchor its subledger
            // entry to the same accounting event — not a second, independent credit.
            $this->executeDisbursementChannel($loan, $data, $netDisbursed, $actorId, $disbursementJe);

            // Step 7 — Link application → loan, transition to disbursed
            $application->disbursed_loan_id = $loan->id;
            $application->disbursed_at = $disbursedAt;
            $application->schedule_date = $scheduleDate;
            $application->save();

            $this->statusGuard->transition(
                $application,
                LoanApplication::STATUS_DISBURSED,
                "Loan {$loan->loan_no} created and disbursed.",
            );

            $loan = $loan->refresh();

            // Attach warnings (e.g. fallback from debit_savings)
            if (! empty($warnings)) {
                $loan->setAttribute('disbursement_warnings', $warnings);
            }

            return $loan;
        });
    }

    // ─── Step 1 helpers ───────────────────────────────────────────────────────

    private function assertDisbursable(LoanApplication $application): void
    {
        if ($application->status !== LoanApplication::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'application' => ['Only approved applications can be disbursed.'],
            ]);
        }

        if ($application->disbursed_loan_id !== null) {
            throw ValidationException::withMessages([
                'application' => ['This application has already been disbursed.'],
            ]);
        }
    }

    private function assertGlMappingComplete(LoanProduct $product): void
    {
        $missing = collect(self::REQUIRED_GL_FIELDS)
            ->filter(fn ($field) => empty($product->$field))
            ->values()
            ->all();

        if (! empty($missing)) {
            throw ValidationException::withMessages([
                'gl_mapping' => [
                    'Incomplete GL mapping on loan product. Missing: '.implode(', ', $missing),
                ],
            ]);
        }
    }

    // ─── Step 2 helpers ───────────────────────────────────────────────────────

    private function calculateProcessingFee(LoanProduct $product, float $principal): float
    {
        $type = (string) ($product->processing_fee_type ?? 'flat');
        $value = (float) ($product->processing_fee_value ?? 0);

        if ($value <= 0) {
            return 0.0;
        }

        return match ($type) {
            'percentage' => round($principal * $value / 100, 2),
            default => round($value, 2), // flat
        };
    }

    // ─── Step 4 helpers ───────────────────────────────────────────────────────

    private function createLoan(
        LoanApplication $application,
        LoanProduct $product,
        array $data,
        ?int $actorId,
        float $processingFee,
        float $chargesTotal,
        string $chargeDeductionMode,
        ?string $chargeReceiptNo,
        float $netDisbursed,
        float $effectivePrincipal,
        Carbon $disbursedAt,
        Carbon $scheduleDate,
    ): Loan {
        return Loan::create([
            'loan_no' => $this->generateLoanNumber(),
            'loan_application_id' => $application->id,
            'member_id' => $application->member_id,
            'loan_product_id' => $application->loan_product_id,
            'branch_id' => $application->branch_id,
            'loan_officer_id' => $application->loan_officer_id,
            'principal' => $effectivePrincipal,
            'processing_fee' => $processingFee,
            'total_charges_deducted' => $chargesTotal,
            'net_disbursed_amount' => $netDisbursed,
            'interest_rate' => $application->approved_interest_rate ?? $application->recommended_interest_rate ?? $product->interest_rate,
            'term_months' => $application->approved_term,
            'disbursed_at' => $disbursedAt,
            'schedule_date' => $scheduleDate,
            'disbursement_method' => $data['disbursement_method'],
            'disbursement_reference' => $data['disbursement_reference'] ?? null,
            'charge_deduction_mode' => $chargeDeductionMode,
            'charge_receipt_no' => $chargeReceiptNo,
            'savings_account_id' => $data['savings_account_id'] ?? null,
            'mobile_money_provider' => $data['mobile_money_provider'] ?? null,
            'mobile_money_number' => $data['mobile_money_number'] ?? null,
            'status' => LoanStatus::Disbursed,
            'outstanding_balance' => $effectivePrincipal,
            'approved_by' => $application->approved_by,
            'disbursed_by' => $actorId,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    private function generateLoanNumber(): string
    {
        $date = now()->format('Ymd');
        $sequence = Loan::whereDate('created_at', now()->toDateString())->count() + 1;

        return sprintf('LN-%s-%05d', $date, $sequence);
    }

    // ─── Public: regenerate schedule from a new start date ────────────────────

    /**
     * Regenerate the repayment schedule for an existing loan using a new
     * schedule start date.  Deletes all unpaid schedule rows and re-creates
     * them.  Already-paid installments are preserved.
     */
    public function regenerateSchedule(Loan $loan, Carbon $newScheduleDate): void
    {
        DB::connection('tenant')->transaction(function () use ($loan, $newScheduleDate) {
            $product = $loan->loanProduct()->firstOrFail();

            // Preserve paid installments – only delete rows that haven't been paid
            $paidCount = LoanSchedule::where('loan_id', $loan->id)
                ->where('status', 'paid')
                ->count();

            LoanSchedule::where('loan_id', $loan->id)
                ->where('status', '!=', 'paid')
                ->delete();

            // Recalculate the schedule rows (amounts stay the same, only dates shift)
            $repaymentCycle = (string) ($product->repayment_cycle ?? 'monthly');

            $result = $this->scheduleGenerator->generate(
                (float) $loan->principal,
                $loan->term_months,
                (string) ($product->interest_method ?? 'flat'),
                (string) ($product->repayment_structure ?? 'equal_installment'),
                (float) $loan->interest_rate,
                (string) ($product->interest_period ?? 'monthly'),
                $repaymentCycle,
            );

            $graceDays = (int) ($product->grace_period ?? 0);
            $baseDate = $newScheduleDate->copy()->addDays($graceDays);

            $loanSettings = LoanSetting::currentForBranch($loan->branch_id);
            $pushHolidays = (bool) ($loanSettings->push_installments_on_holidays ?? false);
            $pushHolidaysWeekdaysOnly = (bool) ($loanSettings->push_installments_on_holidays_weekdays_only ?? false);
            $relativeScheduling = (bool) ($loanSettings->relative_scheduling ?? false);

            $effectivePushHolidays = $pushHolidays || $relativeScheduling;
            $useRelativeScheduling = $relativeScheduling;

            $previousDueDate = null;

            // Only create rows for installments that haven't already been paid
            $rows = collect($result['rows'])->filter(fn ($row) => $row['period'] > $paidCount);

            foreach ($rows as $row) {
                if ($useRelativeScheduling && $previousDueDate instanceof Carbon) {
                    $dueDate = $this->addCycle($previousDueDate->copy(), $repaymentCycle, 1);
                } else {
                    $periodOffset = $row['period'] - 1;
                    $dueDate = $this->addCycle($baseDate->copy(), $repaymentCycle, (int) $periodOffset);
                }

                $dueDate = $this->applyHolidaySchedulingPolicy(
                    $dueDate,
                    $effectivePushHolidays,
                    $pushHolidaysWeekdaysOnly,
                );

                if ($useRelativeScheduling) {
                    $previousDueDate = $dueDate->copy();
                }

                LoanSchedule::create([
                    'loan_id' => $loan->id,
                    'installment_no' => $row['period'],
                    'due_date' => $dueDate,
                    'principal_due' => $row['principal'],
                    'interest_due' => $row['interest'],
                    'total_due' => $row['installment'],
                    'principal_paid' => 0,
                    'interest_paid' => 0,
                    'outstanding_balance' => $row['balance'],
                    'status' => 'pending',
                ]);
            }

            // Re-distribute on_repayment charges across the new unpaid schedule rows
            $onRepaymentCharges = LoanAppliedCharge::where('loan_id', $loan->id)
                ->where('application_timing', 'on_repayment')
                ->where('is_waived', false)
                ->sum('charge_amount');

            if ($onRepaymentCharges > 0) {
                $this->distributeChargesToSchedule($loan, (float) $onRepaymentCharges);
            }
        });
    }

    // ─── Public: post disbursement entry for express topup ────────────────────

    /**
     * Post the disbursement journal entry for a top-up loan created via the
     * express (auto-disburse) flow. The standard disburse() path cannot be
     * used here because no LoanApplication exists for the topup.
     *
     * DR  Loan Portfolio Account  = principal
     * CR  Disbursement Account    = principal
     */
    public function postTopupDisbursementEntry(Loan $newLoan, ?int $actorId): void
    {
        $product = $newLoan->loanProduct()->firstOrFail();

        if (! $product->loan_portfolio_account_id || ! $product->disbursement_account_id) {
            throw ValidationException::withMessages([
                'gl_mapping' => [
                    "Loan product [{$product->id}] is missing GL mapping required for topup disbursement entry.",
                ],
            ]);
        }

        $principal = (float) $newLoan->principal;
        $narration = "Top-up loan disbursement – {$newLoan->loan_no}";

        $lines = [
            $this->line($product->loan_portfolio_account_id, $principal, 0.0, $narration, $newLoan->id),
            $this->line($product->disbursement_account_id, 0.0, $principal, $narration, $newLoan->id),
        ];

        $this->postJournalEntry($newLoan, 'LOAN_DISB', $narration, $lines, $actorId);
    }

    // ─── Step 5 helpers ───────────────────────────────────────────────────────

    /**
     * Generate the full repayment schedule and persist it.
     * Returns the total interest for use in flat-rate accrual entry.
     */
    private function generateSchedule(Loan $loan, LoanProduct $product, Carbon $disbursedAt): float
    {
        $repaymentCycle = (string) ($product->repayment_cycle ?? 'monthly');

        $result = $this->scheduleGenerator->generate(
            (float) $loan->principal,
            $loan->term_months,
            (string) ($product->interest_method ?? 'flat'),
            (string) ($product->repayment_structure ?? 'equal_installment'),
            (float) $loan->interest_rate,
            (string) ($product->interest_period ?? 'monthly'),
            $repaymentCycle,
        );

        $graceDays = (int) ($product->grace_period ?? 0);
        $baseDate = $disbursedAt->copy()->addDays($graceDays);

        $loanSettings = LoanSetting::currentForBranch($loan->branch_id);
        $pushHolidays = (bool) ($loanSettings->push_installments_on_holidays ?? false);
        $pushHolidaysWeekdaysOnly = (bool) ($loanSettings->push_installments_on_holidays_weekdays_only ?? false);
        $relativeScheduling = (bool) ($loanSettings->relative_scheduling ?? false);

        // Relative scheduling is mutually exclusive with the other two in the UI,
        // so push_installments_on_holidays is always false when relative_scheduling is true.
        // We still need holiday pushing active — otherwise relative scheduling is a no-op.
        $effectivePushHolidays = $pushHolidays || $relativeScheduling;
        $useRelativeScheduling = $relativeScheduling;

        $previousDueDate = null;

        foreach ($result['rows'] as $row) {
            if ($useRelativeScheduling && $previousDueDate instanceof Carbon) {
                $dueDate = $this->addCycle($previousDueDate->copy(), $repaymentCycle, 1);
            } else {
                // If there's a grace period, the first installment should be at the base date (disbursement + grace).
                // addCycle adds (period * cycle) to the base date.
                // We want period 1 to be exactly baseDate if graceDays > 0.
                $periodOffset = $row['period'] - 1;
                $dueDate = $this->addCycle($baseDate->copy(), $repaymentCycle, (int) $periodOffset);
            }

            $dueDate = $this->applyHolidaySchedulingPolicy(
                $dueDate,
                $effectivePushHolidays,
                $pushHolidaysWeekdaysOnly,
            );

            if ($useRelativeScheduling) {
                $previousDueDate = $dueDate->copy();
            }

            LoanSchedule::create([
                'loan_id' => $loan->id,
                'installment_no' => $row['period'],
                'due_date' => $dueDate,
                'principal_due' => $row['principal'],
                'interest_due' => $row['interest'],
                'total_due' => $row['installment'],
                'principal_paid' => 0,
                'interest_paid' => 0,
                'outstanding_balance' => $row['balance'],
                'status' => 'pending',
            ]);
        }

        return (float) $result['total_interest'];
    }

    /**
     * Add N repayment cycles to a base date.
     * period 1 = base + 1 cycle, period N = base + N cycles.
     */
    private function addCycle(Carbon $base, string $cycle, int $period): Carbon
    {
        return match ($cycle) {
            'weekly' => $base->addWeeks($period),
            'bi-weekly' => $base->addWeeks($period * 2),
            'bi-monthly' => $base->addMonths($period * 2),
            'quarterly' => $base->addMonths($period * 3),
            'annual' => $base->addYears($period),
            default => $base->addMonths($period), // monthly
        };
    }

    /**
     * Apply holiday scheduling policy for due-date shifting.
     *
     * Settings are independent and additive:
     *  - push_holidays: shift when date is a holiday OR weekend.
     *  - push_holidays_weekdays_only: shift when date is a holiday (weekends ignored).
     *  - Both active: push_holidays wins (broader rule).
     *  - Neither active: no shift.
     */
    private function applyHolidaySchedulingPolicy(
        Carbon $dueDate,
        bool $pushHolidays,
        bool $pushHolidaysWeekdaysOnly,
    ): Carbon {
        if ($pushHolidays) {
            // Broadest rule: push holidays AND weekends.
            return $this->holidayService->getNextWorkingDay($dueDate);
        }

        if ($pushHolidaysWeekdaysOnly) {
            // Narrower rule: push only public holidays, leave weekends alone.
            return $this->holidayService->isHoliday($dueDate)
                ? $this->holidayService->getNextWorkingDay($dueDate)
                : $dueDate;
        }

        return $dueDate;
    }
    // ─── Step 6 helpers — Double-entry accounting ─────────────────────────────

    /**
     * Process all loan charges at disbursement:
     *  1. Distribute on_repayment charges into schedule.charges_due
     *  2. Post accrual JE for on_disbursement charges (only if NOT already handled by deduction mode)
     */
    private function processChargesAtDisbursement(Loan $loan, LoanProduct $product, ?int $actorId, string $chargeDeductionMode): void
    {
        $charges = LoanAppliedCharge::where('loan_id', $loan->id)
            ->where('is_waived', false)
            ->get();

        if ($charges->isEmpty()) {
            return;
        }

        // ── 1. Distribute on_repayment charges into the schedule ──────────────
        $onRepaymentTotal = $charges
            ->where('application_timing', 'on_repayment')
            ->sum('charge_amount');

        if ($onRepaymentTotal > 0 && $product->charges_receivable_account_id && $product->charges_income_account_id) {
            $this->distributeChargesToSchedule($loan, (float) $onRepaymentTotal);
            // Establish the receivable so it can be cleared during repayment
            $this->postChargesAccrualEntry($loan, $product, (float) $onRepaymentTotal, $actorId);
        }

        // ── 2. Post accrual JE for on_disbursement charges ────────────────────
        // Skip if the charge was already recognised directly in the disbursement JE
        // (deduct_from_principal, capitalize, pay_cash all CR Charges Income directly)
        $onDisbursementTotal = $charges
            ->where('application_timing', 'on_disbursement')
            ->sum('charge_amount');

        $alreadyRecognised = in_array($chargeDeductionMode, ['deduct_from_principal', 'capitalize', 'pay_cash']);

        $hasChargeAccounts = $product->charges_receivable_account_id
            && $product->charges_income_account_id;

        if ($onDisbursementTotal > 0 && $hasChargeAccounts && ! $alreadyRecognised) {
            $this->postChargesAccrualEntry($loan, $product, (float) $onDisbursementTotal, $actorId);
        }
    }

    /**
     * Distribute a total charge amount across schedule rows using the
     * distribution mode configured in loan_settings.
     *
     * Mode: evenly | first_installment | last_installment
     */
    private function distributeChargesToSchedule(Loan $loan, float $totalCharges): void
    {
        $schedules = LoanSchedule::where('loan_id', $loan->id)
            ->orderBy('installment_no')
            ->get();

        if ($schedules->isEmpty()) {
            return;
        }

        $mode = $this->resolveChargeDistributionMode($loan);

        match ($mode) {
            'first_installment' => $this->distributeToFirst($schedules, $totalCharges),
            'last_installment' => $this->distributeToLast($schedules, $totalCharges),
            default => $this->distributeEvenly($schedules, $totalCharges),
        };
    }

    private function distributeEvenly($schedules, float $total): void
    {
        $count = $schedules->count();
        $perInstallment = floor($total * 100 / $count) / 100; // round down
        $remainder = round($total - ($perInstallment * $count), 2);

        foreach ($schedules as $i => $schedule) {
            $amount = $perInstallment;
            if ($i === 0) {
                $amount = round($amount + $remainder, 2); // first row absorbs rounding diff
            }

            $schedule->charges_due = $amount;
            $schedule->total_due = round((float) $schedule->total_due + $amount, 2);
            $schedule->save();
        }
    }

    private function distributeToFirst($schedules, float $total): void
    {
        $first = $schedules->first();
        $first->charges_due = $total;
        $first->total_due = round((float) $first->total_due + $total, 2);
        $first->save();
    }

    private function distributeToLast($schedules, float $total): void
    {
        $last = $schedules->last();
        $last->charges_due = $total;
        $last->total_due = round((float) $last->total_due + $total, 2);
        $last->save();
    }

    /**
     * Resolve the charge distribution mode from loan_settings for the loan's branch.
     * Falls back to 'evenly' if not configured.
     */
    private function resolveChargeDistributionMode(Loan $loan): string
    {
        $mode = DB::connection('tenant')
            ->table('loan_settings')
            ->where('branch_id', $loan->branch_id)
            ->value('charge_distribution_mode');

        return $mode ?: 'evenly';
    }

    /**
     * Compound disbursement entry – dynamic per charge-deduction mode.
     *
     * Mode: deduct_from_principal
     *   DR  Loan Portfolio   = principal
     *   CR  Disb Account     = principal − fees − charges
     *   CR  Fee Income       = processing fee
     *   CR  Charges Income   = charges
     *
     * Mode: capitalize
     *   DR  Loan Portfolio   = principal + charges (already in effective_principal)
     *   CR  Disb Account     = principal + charges − fees
     *   CR  Fee Income       = processing fee
     *   CR  Charges Income   = charges
     *
     * Mode: debit_savings (charges taken from savings — separate JE)
     *   DR  Loan Portfolio   = principal
     *   CR  Disb Account     = principal − fees
     *   CR  Fee Income       = processing fee
     *   + Separate JE: DR Member Savings Liability / CR Charges Income
     *
     * Mode: pay_cash (charges paid in cash — separate JE)
     *   DR  Loan Portfolio   = principal
     *   CR  Disb Account     = principal − fees
     *   CR  Fee Income       = processing fee
     *   + Separate JE: DR Petty Cash / CR Charges Income
     *
     * Channel: savings_account → CR Member Savings Liability instead of CR Disb Account
     * Returns the posted JournalEntry so the disbursement channel can anchor its
     * savings-account subledger entry to the same event.
     */
    private function postDisbursementEntry(
        Loan $loan,
        LoanProduct $product,
        float $processingFee,
        float $chargesTotal,
        string $chargeDeductionMode,
        array $data,
        ?int $actorId,
    ): JournalEntry {
        $effectivePrincipal = (float) $loan->principal;
        $netDisbursed = (float) $loan->net_disbursed_amount;
        $narration = "Loan disbursement – {$loan->loan_no}";

        // Determine the CR account for disbursement (bank vs savings)
        $disbCrAccountId = $this->resolveDisbursementCrAccount($product, $data, $loan);

        $lines = [
            $this->line($product->loan_portfolio_account_id, $effectivePrincipal, 0.0, $narration, $loan->id),
            $this->line($disbCrAccountId, 0.0, $netDisbursed, $narration, $loan->id),
        ];

        if ($processingFee > 0) {
            $feeNarration = "Processing fee – {$loan->loan_no}";
            $feeAccountId = $this->resolveProcessingFeeAccountId($product);
            $lines[] = $this->line($feeAccountId, 0.0, $processingFee, $feeNarration, $loan->id);
        }

        // For deduct_from_principal and capitalize, charges income recognised directly
        if (in_array($chargeDeductionMode, ['deduct_from_principal', 'capitalize']) && $chargesTotal > 0 && $product->charges_income_account_id) {
            $chargeNarration = "Charge deducted ({$chargeDeductionMode}) – {$loan->loan_no}";
            $lines[] = $this->line($product->charges_income_account_id, 0.0, $chargesTotal, $chargeNarration, $loan->id);
        }

        $disbursementJe = $this->postJournalEntry($loan, 'LOAN_DISB', $narration, $lines, $actorId);

        // Separate JEs for debit_savings and pay_cash
        if ($chargesTotal > 0 && $product->charges_income_account_id) {
            if ($chargeDeductionMode === 'debit_savings') {
                $this->postSavingsChargeDeductionJE($loan, $product, $chargesTotal, $actorId);
            } elseif ($chargeDeductionMode === 'pay_cash') {
                $this->postCashChargeCollectionJE($loan, $product, $chargesTotal, $actorId);
            }
        }

        return $disbursementJe;
    }

    /**
     * Resolve the GL account to CR for the disbursed amount.
     *
     * savings_account channel → correct Member Savings Liability GL (2111/2112/2113)
     *                           resolved from the target savings account's product type.
     * All other channels      → product->disbursement_account_id
     */
    private function resolveDisbursementCrAccount(LoanProduct $product, array $data, Loan $loan): int
    {
        if (($data['disbursement_method'] ?? '') === 'savings_account'
            && ! empty($data['savings_account_id'])) {

            $savings = SavingsAccount::with('savingsProduct')->find((int) $data['savings_account_id']);

            if ($savings) {
                $glCode = $this->resolveSavingsLiabilityGlCode($savings);
                $accountId = ChartOfAccount::on('tenant')
                    ->where('gl_code', $glCode)
                    ->where('is_active', true)
                    ->value('id');

                if ($accountId) {
                    return (int) $accountId;
                }
            }
        }

        return (int) $product->disbursement_account_id;
    }

    /**
     * Return the correct savings liability GL code for a savings account.
     *
     * Resolution order: account_type column (mandatory / voluntary / fixed) takes
     * precedence, with savings_products.type as fallback for fixed-deposit detection.
     *
     *   mandatory  → 2111
     *   fixed      → 2113
     *   All others → 2112 (Voluntary, default)
     */
    protected function resolveSavingsLiabilityGlCode(SavingsAccount $savings): string
    {
        $type = strtolower($savings->account_type ?? $savings->savingsProduct?->type ?? '');

        return match (true) {
            str_contains($type, 'mandatory') => GlCodes::SAVINGS_MANDATORY,
            str_contains($type, 'fixed') => GlCodes::SAVINGS_FIXED_DEPOSIT,
            default => GlCodes::SAVINGS_VOLUNTARY,
        };
    }

    /**
     * Flat-rate interest accrual (upfront recognition).
     *
     *   DR  Interest Receivable      = total_interest  (future income owed to SACCO)
     *   CR  Interest Income          = total_interest  (income recognised upfront)
     *
     * Only posted for flat-rate products. Reducing-balance interest is
     * recognised period-by-period during repayment posting.
     */
    private function postInterestAccrualEntry(Loan $loan, LoanProduct $product, float $totalInterest, ?int $actorId): void
    {
        $narration = "Interest accrual (flat) – {$loan->loan_no}";

        $lines = [
            $this->line($product->interest_receivable_account_id, $totalInterest, 0.0, $narration, $loan->id),
            $this->line($product->interest_income_account_id, 0.0, $totalInterest, $narration, $loan->id),
        ];

        $this->postJournalEntry($loan, 'LOAN_INT_ACC', $narration, $lines, $actorId);
    }

    /**
     * Charge accrual entry — establishes Charges Receivable at disbursement time.
     *
     * Used for:
     *  - on_disbursement charges when deduction mode is debit_savings (receivable cleared when savings are debited)
     *  - on_repayment charges (receivable cleared installment-by-installment during repayment)
     *
     *   DR  Charges Receivable  = total charges  (SACCO is owed this)
     *   CR  Charges Income      = total charges  (income recognised upfront)
     */
    private function postChargesAccrualEntry(Loan $loan, LoanProduct $product, float $totalCharges, ?int $actorId): void
    {
        $narration = "Charge accrual – {$loan->loan_no}";

        $lines = [
            $this->line($product->charges_receivable_account_id, $totalCharges, 0.0, $narration, $loan->id),
            $this->line($product->charges_income_account_id, 0.0, $totalCharges, $narration, $loan->id),
        ];

        $this->postJournalEntry($loan, 'LOAN_CHG_ACC', $narration, $lines, $actorId);
    }

    /**
     * JE for debit_savings charge deduction.
     * Debits the correct Member Savings Liability GL based on the loan's savings account product type.
     *
     *   DR  Member Savings Liability (2111 / 2112 / 2113)  = charges
     *   CR  Charges Income                                  = charges
     */
    private function postSavingsChargeDeductionJE(Loan $loan, LoanProduct $product, float $chargesTotal, ?int $actorId): void
    {
        $savingsGl = null;

        if ($loan->savings_account_id) {
            $savings = SavingsAccount::with('savingsProduct')->find((int) $loan->savings_account_id);

            if ($savings) {
                $glCode = $this->resolveSavingsLiabilityGlCode($savings);
                $savingsGl = ChartOfAccount::on('tenant')
                    ->where('gl_code', $glCode)
                    ->where('is_active', true)
                    ->first();
            }
        }

        // Fallback to 2111 when no savings account is linked.
        if (! $savingsGl) {
            $savingsGl = ChartOfAccount::on('tenant')
                ->where('gl_code', GlCodes::SAVINGS_MANDATORY)
                ->where('is_active', true)
                ->first();
        }

        if (! $savingsGl) {
            return;
        }

        $narration = "Charge deduction from savings – {$loan->loan_no}";

        $lines = [
            $this->line($savingsGl->id, $chargesTotal, 0.0, $narration, $loan->id),
            $this->line((int) $product->charges_income_account_id, 0.0, $chargesTotal, $narration, $loan->id),
        ];

        $this->postJournalEntry($loan, 'LOAN_CHG_SAV', $narration, $lines, $actorId);
    }

    /**
     * JE for pay_cash charge collection:
     *   DR  Petty Cash (1111)    = charges  (cash received by SACCO)
     *   CR  Charges Income       = charges  (income recognised)
     */
    private function postCashChargeCollectionJE(Loan $loan, LoanProduct $product, float $chargesTotal, ?int $actorId): void
    {
        $cashGl = ChartOfAccount::on('tenant')->where('gl_code', GlCodes::PETTY_CASH)->first();
        if (! $cashGl) {
            return;
        }

        $narration = "Charge collected in cash – {$loan->loan_no}";

        $lines = [
            $this->line($cashGl->id, $chargesTotal, 0.0, $narration, $loan->id),
            $this->line($product->charges_income_account_id, 0.0, $chargesTotal, $narration, $loan->id),
        ];

        $this->postJournalEntry($loan, 'LOAN_CHG_CASH', $narration, $lines, $actorId);
    }

    // ─── Step 4b — Persist applied charges ───────────────────────────────────

    /**
     * Persist one loan_applied_charges row per charge so that:
     *  - processChargesAtDisbursement() can distribute on_repayment charges to the schedule
     *  - The loan account charges tab can display a full breakdown
     *
     * Sources:
     *  1. Processing fee (product-level flat field, always on_disbursement)
     *  2. All active general_charges linked to this product via loan_product_ids JSON
     */
    private function persistAppliedCharges(
        Loan $loan,
        LoanProduct $product,
        float $processingFee,
        float $originalPrincipal,
    ): void {
        // 1. Processing fee — stored with charge_id = null (not from general_charges)
        if ($processingFee > 0) {
            LoanAppliedCharge::create([
                'loan_id' => $loan->id,
                'charge_id' => null,
                'name' => 'Processing Fee',
                'charge_type' => $product->processing_fee_type ?? 'flat',
                'application_timing' => 'on_disbursement',
                'charge_amount' => $processingFee,
                'default_amount' => $processingFee,
                'used_amount' => $processingFee, // collected at disbursement
                'is_waived' => false,
                'is_mandatory' => true,
            ]);
        }

        // 2. Loan charges linked to this product via the loan_product_charge pivot.
        // Skip 'processing_fee' category charges — the product-level processing_fee_value
        // field already handles that category in step 1 above. Allowing both would create
        // a duplicate "Processing Fee" row in loan_applied_charges.
        $loanCharges = $product->charges()->where('is_active', true)->get();

        foreach ($loanCharges as $charge) {
            if ($processingFee > 0 && $charge->category === 'processing_fee') {
                continue;
            }
            $amount = $charge->charge_type === 'percentage'
                ? round($originalPrincipal * (float) $charge->value / 100, 2)
                : round((float) $charge->value, 2);

            // LoanCharge has no where_to_apply field; timing is derived from category alone
            $timing = $this->getChargeTiming($charge->category, null);

            LoanAppliedCharge::create([
                'loan_id' => $loan->id,
                'charge_id' => $charge->id,
                'name' => $charge->name,
                'charge_type' => $charge->charge_type,
                'application_timing' => $timing,
                'charge_amount' => $amount,
                'default_amount' => $amount,
                'used_amount' => $timing === 'on_disbursement' ? $amount : 0,
                'is_waived' => false,
                'is_mandatory' => true,
            ]);
        }
    }

    // ─── Step 2 helpers — Charge deduction mode logic ─────────────────────────

    /**
     * Sum all non-waived on_disbursement charges for the application's loan.
     * Charges are linked to the application before the loan is created,
     * so we query by the application's anticipated loan_id or by loan_charges
     * that exist once the loan is created. Since charges may be attached
     * before the Loan record exists, we look them up by loan_product + member.
     */
    private function sumOnDisbursementCharges(LoanApplication $application): float
    {
        $product = $application->loanProduct;
        $principal = (float) $application->approved_amount;

        if (! $product) {
            return 0;
        }

        // Loan charges are stored in loan_charges and linked to products via the
        // loan_product_charge pivot (LoanCharge model / $product->charges relation).
        // On-disbursement charges are any active charges that are NOT penalty/late_fee
        // (which are on-repayment). This matches the frontend's resolveProductCharges()
        // logic in LoanApplicationResource.
        // Skip 'processing_fee' category charges when the product has its own
        // processing_fee_value configured — that field is the authoritative source
        // and is handled separately via calculateProcessingFee().
        $productProcessingFee = $this->calculateProcessingFee($product, $principal);

        return (float) $product->charges()
            ->where('is_active', true)
            ->whereNotIn('category', ['penalty', 'late_fee'])
            ->get()
            ->filter(fn (LoanCharge $charge) => ! ($productProcessingFee > 0 && $charge->category === 'processing_fee'))
            ->sum(function (LoanCharge $charge) use ($principal) {
                return $charge->charge_type === 'percentage'
                    ? round($principal * (float) $charge->value / 100, 2)
                    : round((float) $charge->value, 2);
            });
    }

    /**
     * Resolve the charge deduction mode.
     * If the request explicitly provides a mode, it overrides the branch loan_settings.
     * Falls back to 'deduct_from_principal'.
     */
    private function resolveChargeDeductionMode(LoanApplication $application, ?string $requestedMode = null): string
    {
        if ($requestedMode !== null) {
            return $requestedMode;
        }

        $mode = DB::connection('tenant')
            ->table('loan_settings')
            ->where('branch_id', $application->branch_id)
            ->value('charge_deduction_mode');

        return $mode ?: 'deduct_from_principal';
    }

    /**
     * Apply the charge deduction mode and return net disbursement details.
     */
    private function applyChargeDeductionMode(
        string $mode,
        float $principal,
        float $processingFee,
        float $chargesTotal,
        LoanApplication $application,
        array &$warnings,
    ): array {
        $receiptNo = null;

        if ($chargesTotal <= 0) {
            // No charges — simple case
            return [
                'net_disbursed' => round($principal - $processingFee, 2),
                'effective_principal' => $principal,
                'mode' => $mode,
                'receipt_no' => null,
            ];
        }

        return match ($mode) {
            'capitalize' => [
                'net_disbursed' => round($principal - $processingFee, 2),
                'effective_principal' => round($principal + $chargesTotal, 2),
                'mode' => 'capitalize',
                'receipt_no' => null,
            ],
            'debit_savings' => $this->applyDebitSavingsMode(
                $principal, $processingFee, $chargesTotal, $application, $warnings,
            ),
            'pay_cash' => [
                'net_disbursed' => round($principal - $processingFee, 2),
                'effective_principal' => $principal,
                'mode' => 'pay_cash',
                'receipt_no' => $this->generateChargeReceiptNo(),
            ],
            default => [ // deduct_from_principal
                'net_disbursed' => round($principal - $processingFee - $chargesTotal, 2),
                'effective_principal' => $principal, // Fixed: principal remains gross
                'mode' => 'deduct_from_principal',
                'receipt_no' => null,
            ],
        };
    }

    /**
     * Attempt debit_savings. Falls back to deduct_from_principal if insufficient balance.
     */
    private function applyDebitSavingsMode(
        float $principal,
        float $processingFee,
        float $chargesTotal,
        LoanApplication $application,
        array &$warnings,
    ): array {
        $savings = SavingsAccount::where('member_id', $application->member_id)
            ->where('status', 'active')
            ->orderByDesc('balance')
            ->first();

        if ($savings instanceof SavingsAccount && (float) $savings->balance >= $chargesTotal) {
            // Debit savings
            $savings->balance = round((float) $savings->balance - $chargesTotal, 2);
            $savings->save();

            Transaction::create([
                'reference' => 'CHGDEBIT-'.date('Ymd').'-'.mt_rand(10000, 99999),
                'member_id' => $application->member_id,
                'type' => 'charge_deduction',
                'amount' => $chargesTotal,
                'payment_mode' => 'system',
                'deposited_by' => 'System (Charge Deduction)',
                'transaction_date' => now()->toDateString(),
                'account_id' => $savings->id,
                'account_type' => SavingsAccount::class,
                'narration' => 'Loan disbursement charge deducted from savings',
                'created_by' => auth('tenant')->id(),
            ]);

            return [
                'net_disbursed' => round($principal - $processingFee, 2),
                'effective_principal' => $principal,
                'mode' => 'debit_savings',
                'receipt_no' => null,
            ];
        }

        // Fallback: insufficient savings → deduct from principal
        $warnings[] = 'Insufficient savings balance to cover charges ('
            .($savings ? number_format((float) $savings->balance, 2) : '0.00')
            .' available, '.number_format($chargesTotal, 2)
            .' needed). Charges deducted from principal instead.';

        return [
            'net_disbursed' => round($principal - $processingFee - $chargesTotal, 2),
            'effective_principal' => $principal, // Fixed: principal remains gross
            'mode' => 'deduct_from_principal',
            'receipt_no' => null,
        ];
    }

    /**
     * Generate a unique receipt number for pay_cash charge collections.
     */
    private function generateChargeReceiptNo(): string
    {
        $date = now()->format('Ymd');
        $sequence = Transaction::on('tenant')
            ->where('type', 'charge_cash_payment')
            ->whereDate('created_at', now()->toDateString())
            ->count() + 1;

        return sprintf('CHGRCPT-%s-%05d', $date, $sequence);
    }

    // ─── Step 6d — Disbursement channel execution ────────────────────────────

    /**
     * Execute the physical disbursement action based on channel.
     * $disbursementJe is passed through so disburseToSavings() can anchor its
     * savings-account subledger to the same journal entry (not a second credit event).
     */
    private function executeDisbursementChannel(Loan $loan, array $data, float $netAmount, ?int $actorId, ?JournalEntry $disbursementJe = null): void
    {
        match ($data['disbursement_method'] ?? '') {
            'savings_account' => $this->disburseToSavings($loan, $data, $netAmount, $actorId, $disbursementJe),
            // mobile_money, bank_transfer, cheque, cash → manual process, metadata already recorded on loan
            default => null,
        };

        // Record cash charge payment transaction (separate from disbursement)
        if (($loan->charge_deduction_mode === 'pay_cash') && (float) $loan->total_charges_deducted > 0) {
            Transaction::create([
                'reference' => $loan->charge_receipt_no ?? ('CHGCASH-'.$loan->loan_no),
                'receipt_number' => $loan->charge_receipt_no,
                'member_id' => $loan->member_id,
                'type' => 'charge_cash_payment',
                'amount' => (float) $loan->total_charges_deducted,
                'payment_mode' => 'cash',
                'deposited_by' => 'Member (Cash Charge Payment)',
                'transaction_date' => now()->toDateString(),
                'account_id' => $loan->id,
                'account_type' => Loan::class,
                'narration' => "Charge paid in cash at disbursement – {$loan->loan_no}",
                'created_by' => $actorId,
            ]);
        }
    }

    /**
     * Credit the member's savings account with the disbursed amount.
     *
     * This is the physical mirror of the GL CR to 2111 already posted in
     * postDisbursementEntry(). The two operations are the SAME economic event:
     *   - The JE records it in double-entry (DR Loan Portfolio / CR 2111)
     *   - This method reflects it on the member's actual savings account balance
     * They are NOT additive. No cash leaves the institution.
     *
     * We also post a savings-account-linked subledger entry for 2111 so the
     * sub-ledger can be reconciled to individual savings accounts, not just to loans.
     */
    private function disburseToSavings(Loan $loan, array $data, float $netAmount, ?int $actorId, ?JournalEntry $disbursementJe = null): void
    {
        $accountId = $data['savings_account_id'] ?? null;

        if (! $accountId) {
            return;
        }

        $savings = SavingsAccount::findOrFail($accountId);

        // Validate ownership
        if ($savings->member_id !== $loan->member_id) {
            throw ValidationException::withMessages([
                'savings_account_id' => ['Savings account does not belong to this member.'],
            ]);
        }

        $narration = "Loan disbursement to savings – {$loan->loan_no}";

        // Credit savings balance — mirrors the GL CR to 2111 (same event, not a second credit)
        $savings->balance = round((float) $savings->balance + $netAmount, 2);
        $savings->save();

        // Post a savings-account-linked subledger entry for 2111 so the subledger
        // reconciles to the individual savings account, not just to the loan entity.
        $savingsGl = ChartOfAccount::on('tenant')->where('gl_code', GlCodes::SAVINGS_MANDATORY)->first();
        if ($savingsGl && $disbursementJe) {
            $this->gl->postToSubLedger(
                $disbursementJe->id,
                $savingsGl->id,
                $savings->id,
                SavingsAccount::class,
                0.0,
                $netAmount,
                $loan->disbursed_at,
                $narration,
                'CR',
            );
        }

        // Record transaction as 'loan_disbursement' (not 'deposit') so it is excluded
        // from net-new-deposit totals and AML deposit threshold reporting.
        Transaction::create([
            'reference' => 'LOAN-DISB-'.$loan->loan_no,
            'member_id' => $loan->member_id,
            'type' => 'loan_disbursement',
            'amount' => $netAmount,
            'charge_amount' => 0.0,
            'payment_mode' => 'loan_disbursement',
            'deposited_by' => 'System (Loan Disbursement)',
            'transaction_date' => now()->toDateString(),
            'account_id' => $savings->id,
            'account_type' => SavingsAccount::class,
            'narration' => $narration,
            'created_by' => $actorId,
        ]);
    }

    // ─── Journal entry primitives ─────────────────────────────────────────────

    private function line(int $accountId, float $debit, float $credit, string $narration, int $loanId): array
    {
        return compact('accountId', 'debit', 'credit', 'narration', 'loanId');
    }

    private function postJournalEntry(
        Loan $loan,
        string $typeCode,
        string $narration,
        array $lines,
        ?int $actorId,
    ): JournalEntry {
        $date = $loan->disbursed_at;

        $je = JournalEntry::create([
            'entry_no' => $this->sequence->nextEntryNo($typeCode),
            'date' => $date,
            'period_date' => $date,
            'fiscal_period' => $date->format('Y-m'),
            'journal_type' => 'loan',
            'reference' => $loan->loan_no,
            'reference_type' => 'loan',
            'narration' => $narration,
            'status' => 'posted',
            'is_system' => true,
            'posted_by' => $actorId,
            'posted_at' => now(),
            'branch_id' => $loan->branch_id,
        ]);

        foreach ($lines as $lineNo => $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $je->id,
                'account_id' => $line['accountId'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'narration' => $line['narration'],
                'loan_id' => $line['loanId'],
                'member_id' => $loan->member_id,
                'branch_id' => $loan->branch_id,
                'line_no' => $lineNo + 1,
            ]);

            $account = ChartOfAccount::on('tenant')->find($line['accountId']);
            $normalBalance = $account?->normal_balance ?? 'DR';
            $this->gl->postToGeneralLedger($je->id, $line['accountId'], (float) $line['debit'], (float) $line['credit'], $date, $line['narration'], $normalBalance);
            $this->gl->postToSubLedger($je->id, $line['accountId'], $loan->id, Loan::class, (float) $line['debit'], (float) $line['credit'], $date, $line['narration'], $normalBalance);
        }

        return $je;
    }

    /**
     * Map database categories and template settings to specific application timing keywords.
     */
    private function getChargeTiming(?string $category, ?string $whereToApply): string
    {
        // Priority 1: Force disbursement for known fee types
        if (in_array($category, ['processing_fee', 'disbursement_fee', 'appraisal_fee', 'insurance', 'other'])) {
            return 'on_disbursement';
        }

        // Priority 2: Use the template setting if provided
        if ($whereToApply === 'on_disbursement') {
            return 'on_disbursement';
        }

        return 'on_repayment';
    }

    /**
     * Resolve the income account for the loan processing fee.
     * Uses GL 4220 (Loan Processing Fees) if it exists in the COA,
     * otherwise falls back to the product's charges_income_account_id.
     */
    private function resolveProcessingFeeAccountId(LoanProduct $product): int
    {
        $id = ChartOfAccount::on('tenant')
            ->where('gl_code', GlCodes::FEE_LOAN_PROCESSING)
            ->where('is_active', true)
            ->value('id');

        return $id ?? (int) $product->charges_income_account_id ?? (int) $product->interest_income_account_id;
    }
}
