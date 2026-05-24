<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Accounting\GlCodes;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\LoanAccountingService;
use App\Tenant\Modules\Loans\Contracts\ScheduleGeneratorServiceInterface;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Models\LoanReschedule;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Modules\Loans\Models\LoanStatusHistory;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Settings\Models\LoanSetting;
use App\Tenant\Modules\Settings\Services\HolidayService;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoanRescheduleService
{
    public function __construct(
        protected ScheduleGeneratorServiceInterface $scheduleGenerator,
        protected HolidayService $holidayService,
        protected LoanAccountingService $loanAccounting,
    ) {}

    // ─── Preview (no side effects) ────────────────────────────────────────────

    /**
     * Calculate and return what the rescheduled loan would look like.
     */
    public function preview(Loan $loan, array $params): array
    {
        $this->validateEligibility($loan);

        $product = $loan->loanProduct()->firstOrFail();
        $snapshot = $this->takeSnapshot($loan);
        $calculation = $this->calculateNewTerms($loan, $product, $params, $snapshot);

        $scheduleResult = $this->scheduleGenerator->generate(
            $calculation['new_principal'],
            $calculation['new_tenor'],
            (string) ($product->interest_method ?? 'flat'),
            (string) ($product->repayment_structure ?? 'equal_installment'),
            $calculation['new_rate'],
            (string) ($product->interest_period ?? 'monthly'),
            (string) ($product->repayment_cycle ?? 'monthly'),
        );

        $rescheduleDate = Carbon::parse($params['reschedule_date'] ?? now());
        $previewSchedule = $this->buildPreviewSchedule($scheduleResult['rows'], $rescheduleDate, $product, $loan);

        return [
            'old_snapshot' => $snapshot,
            'new_snapshot' => [
                'principal_balance' => $calculation['new_principal'],
                'interest_rate' => $calculation['new_rate'],
                'tenor_months' => $calculation['new_tenor'],
                'installment_amount' => $scheduleResult['installment_amount'],
                'maturity_date' => ! empty($previewSchedule)
                    ? $previewSchedule[count($previewSchedule) - 1]['due_date']
                    : null,
                'total_interest' => $scheduleResult['total_interest'],
            ],
            'capitalized_arrears' => $calculation['capitalized_arrears'],
            'capitalized_interest' => $calculation['capitalized_interest'],
            'penalties_waived' => $calculation['penalties_waived'],
            'interest_waived' => $calculation['interest_waived'],
            'preview_schedule' => $previewSchedule,
        ];
    }

    // ─── Execute (persists changes) ───────────────────────────────────────────

    /**
     * Execute the reschedule inside a database transaction.
     */
    public function execute(Loan $loan, array $params, int $actorId): LoanReschedule
    {
        return DB::connection('tenant')->transaction(function () use ($loan, $params, $actorId) {
            $this->validateEligibility($loan);

            $product = $loan->loanProduct()->firstOrFail();
            $oldStatus = $loan->status instanceof \BackedEnum ? $loan->status->value : (string) $loan->status;
            $snapshot = $this->takeSnapshot($loan);
            $calculation = $this->calculateNewTerms($loan, $product, $params, $snapshot);
            $rescheduleDate = Carbon::parse($params['reschedule_date'] ?? now());

            // Load branch settings and compute reschedule fees
            $settings = LoanSetting::currentForBranch($loan->branch_id ?? 0);
            $feesResult = $this->computeRescheduleFees($loan, $settings, $params, $snapshot, $calculation['new_principal']);
            $finalPrincipal = round($calculation['new_principal'] + $feesResult['capitalize_total'], 2);

            // 1. Mark old pending schedule rows as superseded
            LoanSchedule::where('loan_id', $loan->id)
                ->whereIn('status', ['pending', 'partial', 'arrears'])
                ->update(['status' => 'superseded']);

            // 2. Generate new schedule
            $scheduleResult = $this->scheduleGenerator->generate(
                $finalPrincipal,
                $calculation['new_tenor'],
                (string) ($product->interest_method ?? 'flat'),
                (string) ($product->repayment_structure ?? 'equal_installment'),
                $calculation['new_rate'],
                (string) ($product->interest_period ?? 'monthly'),
                (string) ($product->repayment_cycle ?? 'monthly'),
            );

            // 3. Create the reschedule event record
            $rescheduleEvent = LoanReschedule::create([
                'reschedule_id' => 'RSC-'.strtoupper(Str::random(8)),
                'original_loan_id' => $loan->id,
                'reschedule_date' => $rescheduleDate,
                'reschedule_type' => $params['reschedule_type'],
                'old_status' => $oldStatus,
                'old_outstanding' => $snapshot['outstanding_balance'],
                'old_interest_rate' => $snapshot['interest_rate'],
                'old_remaining_periods' => $snapshot['remaining_periods'],
                'old_maturity_date' => $snapshot['maturity_date'],
                'new_principal' => $calculation['new_principal'],
                'new_rate' => $calculation['new_rate'],
                'new_duration' => $calculation['new_tenor'],
                'new_maturity_date' => null, // set after schedule rows are created
                'capitalized_arrears' => $calculation['capitalized_arrears'],
                'capitalized_interest' => $calculation['capitalized_interest'],
                'penalties_waived' => $calculation['penalties_waived'],
                'interest_waived' => $calculation['interest_waived'],
                'reason' => $params['reason'] ?? null,
                'approved_by' => $actorId,
                'performed_by' => $actorId,
                'fees_applied' => $feesResult['fees_detail'] ?: null,
                'old_product_id' => $loan->loan_product_id,
                'new_product_id' => isset($params['new_loan_product_id']) && (int) $params['new_loan_product_id'] !== (int) $loan->loan_product_id
                    ? (int) $params['new_loan_product_id']
                    : null,
            ]);

            // 4. Persist new schedule rows
            $newMaturityDate = $this->persistNewSchedule(
                $loan, $product, $rescheduleEvent, $scheduleResult['rows'], $rescheduleDate
            );

            // Update maturity date on the reschedule record
            $rescheduleEvent->update(['new_maturity_date' => $newMaturityDate]);

            // 5. Update the loan master record
            $updateData = [
                'outstanding_balance' => $calculation['new_principal'],
                'term_months' => $calculation['new_tenor'],
                'interest_rate' => $calculation['new_rate'],
                'is_rescheduled' => true,
                'reschedule_count' => ($loan->reschedule_count ?? 0) + 1,
                'status' => LoanStatus::Rescheduled,
            ];

            // Snapshot original values on first reschedule
            if (! $loan->is_rescheduled) {
                $updateData['original_term_months'] = $loan->term_months;
                $updateData['original_interest_rate'] = $loan->interest_rate;
            }

            // Apply product change if requested
            if (isset($params['new_loan_product_id']) && (int) $params['new_loan_product_id'] !== (int) $loan->loan_product_id) {
                $updateData['loan_product_id'] = (int) $params['new_loan_product_id'];
                $product = LoanProduct::on('tenant')->findOrFail((int) $params['new_loan_product_id']);
            }

            $loan->update($updateData);

            // 6. Log status history activity
            LoanStatusHistory::create([
                'loan_id' => $loan->id,
                'from_status' => $loan->status->value ?? $loan->status,
                'to_status' => $loan->status->value ?? $loan->status,
                'changed_by' => $actorId,
                'notes' => "Loan rescheduled ({$params['reschedule_type']}). "
                    ."Balance: {$snapshot['outstanding_balance']} → {$calculation['new_principal']}, "
                    ."Term: {$snapshot['remaining_periods']} → {$calculation['new_tenor']} months, "
                    ."Rate: {$snapshot['interest_rate']}% → {$calculation['new_rate']}%.",
                'changed_at' => now(),
            ]);

            // 7. Post accounting entries (capitalisation reclassification + waivers)
            $this->postRescheduleAccountingEntries(
                loan: $loan,
                product: $product,
                penaltiesWaived: (float) ($calculation['penalties_waived'] ?? 0),
                interestWaived: (float) ($calculation['interest_waived'] ?? 0),
                capInterest: (float) ($calculation['cap_interest_arrears'] ?? 0),
                capPenalties: (float) ($calculation['cap_penalty_arrears'] ?? 0),
                capCharges: (float) ($calculation['cap_charges_arrears'] ?? 0),
                rescheduleDate: $rescheduleDate,
                actorId: $actorId,
            );

            // Post fee accounting entries (savings, cash, and capitalize)
            $this->postRescheduleFeeAccountingEntries(
                loan: $loan,
                product: $product,
                settings: $settings,
                feesDetail: $feesResult['fees_detail'],
                rescheduleDate: $rescheduleDate,
                actorId: $actorId,
            );

            return $rescheduleEvent;
        });
    }

    // ─── Eligibility validation ───────────────────────────────────────────────

    public function validateEligibility(Loan $loan): void
    {
        $allowedStatuses = ['disbursed', 'active', 'arrears'];
        $loanStatus = $loan->status instanceof \BackedEnum ? $loan->status->value : $loan->status;

        if (! in_array($loanStatus, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'loan' => ['Only disbursed, active, or arrears loans can be rescheduled.'],
            ]);
        }

        // Check product-level allow_reschedule
        $product = $loan->loanProduct;
        if ($product && ! $product->allow_reschedule) {
            throw ValidationException::withMessages([
                'loan' => ['Rescheduling is not allowed for this loan product.'],
            ]);
        }

        // Check branch-level loan_settings
        if ($loan->branch_id) {
            $settings = LoanSetting::currentForBranch($loan->branch_id);

            // if (! $settings->allow_reschedule) {
            //     throw ValidationException::withMessages([
            //         'loan' => ['Rescheduling is disabled for this branch.'],
            //     ]);
            // }

            $maxCount = $settings->max_reschedule_count ?? 3;
            if (($loan->reschedule_count ?? 0) >= $maxCount) {
                throw ValidationException::withMessages([
                    'loan' => ["This loan has reached the maximum reschedule limit ({$maxCount})."],
                ]);
            }
        }

        // At least 1 installment must have been paid (prevents immediate abuse)
        $paidCount = LoanSchedule::where('loan_id', $loan->id)
            ->where('status', 'paid')
            ->count();

        if ($paidCount < 1) {
            throw ValidationException::withMessages([
                'loan' => ['At least one installment must be paid before rescheduling.'],
            ]);
        }
    }

    // ─── Fee computation ──────────────────────────────────────────────────────

    public function computeRescheduleFees(
        Loan $loan,
        LoanSetting $settings,
        array $params,
        array $snapshot,
        float $newPrincipal,
    ): array {
        $capitalizeTotal = 0.0;
        $feesDetail = [];

        $isProductChange = isset($params['new_loan_product_id'])
            && (int) $params['new_loan_product_id'] !== (int) $loan->loan_product_id;

        $candidates = [
            [
                'name' => 'Reschedule Fee',
                'enabled' => (bool) $settings->reschedule_fee_enabled,
                'type' => (string) ($settings->reschedule_fee_type ?? 'flat'),
                'amount' => (float) ($settings->reschedule_fee_amount ?? 0),
                'basis' => (string) ($settings->reschedule_fee_basis ?? ''),
                'collection' => (string) ($settings->reschedule_fee_collection ?? 'cash'),
                'applies' => true,
            ],
            [
                'name' => 'Product Change Fee',
                'enabled' => (bool) $settings->reschedule_product_change_fee_enabled,
                'type' => (string) ($settings->reschedule_product_change_fee_type ?? 'flat'),
                'amount' => (float) ($settings->reschedule_product_change_fee_amount ?? 0),
                'basis' => (string) ($settings->reschedule_product_change_fee_basis ?? ''),
                'collection' => (string) ($settings->reschedule_product_change_fee_collection ?? 'cash'),
                'applies' => $isProductChange,
            ],
            [
                'name' => 'Same Product Fee',
                'enabled' => (bool) $settings->reschedule_same_product_fee_enabled,
                'type' => (string) ($settings->reschedule_same_product_fee_type ?? 'flat'),
                'amount' => (float) ($settings->reschedule_same_product_fee_amount ?? 0),
                'basis' => (string) ($settings->reschedule_same_product_fee_basis ?? ''),
                'collection' => (string) ($settings->reschedule_same_product_fee_collection ?? 'cash'),
                'applies' => ! $isProductChange,
            ],
            [
                'name' => 'Other Charges',
                'enabled' => (bool) $settings->reschedule_other_charges_enabled,
                'type' => (string) ($settings->reschedule_other_charges_type ?? 'flat'),
                'amount' => (float) ($settings->reschedule_other_charges_amount ?? 0),
                'basis' => (string) ($settings->reschedule_other_charges_basis ?? ''),
                'collection' => (string) ($settings->reschedule_other_charges_collection ?? 'cash'),
                'applies' => (bool) ($params['apply_other_charges'] ?? false),
            ],
        ];

        foreach ($candidates as $candidate) {
            if (! $candidate['enabled'] || ! $candidate['applies']) {
                continue;
            }

            $amount = $candidate['type'] === 'percentage'
                ? $this->resolveBasisAmount($candidate['basis'], $snapshot, $newPrincipal, $loan) * ($candidate['amount'] / 100)
                : (float) $candidate['amount'];

            $amount = round($amount, 2);

            if ($amount <= 0) {
                continue;
            }

            $feesDetail[] = [
                'name' => $candidate['name'],
                'amount' => $amount,
                'collection' => $candidate['collection'],
            ];

            if ($candidate['collection'] === 'capitalize') {
                $capitalizeTotal = round($capitalizeTotal + $amount, 2);
            }
        }

        return [
            'capitalize_total' => $capitalizeTotal,
            'fees_detail' => $feesDetail,
        ];
    }

    private function resolveBasisAmount(string $basis, array $snapshot, float $newPrincipal, Loan $loan): float
    {
        return match ($basis) {
            'outstanding_balance' => (float) $snapshot['outstanding_balance'],
            'new_principal' => $newPrincipal,
            'original_disbursed' => (float) ($loan->net_disbursed_amount ?? 0),
            default => (float) $snapshot['outstanding_balance'],
        };
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function takeSnapshot(Loan $loan): array
    {
        $remainingPeriods = LoanSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'partial', 'arrears'])
            ->count();

        $lastSchedule = LoanSchedule::where('loan_id', $loan->id)
            ->orderByDesc('due_date')
            ->first();

        // Calculate arrears amount (unpaid portions of overdue installments)
        $arrearsAmount = LoanSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['arrears', 'partial'])
            ->where('due_date', '<', now())
            ->selectRaw('COALESCE(SUM(
                GREATEST(0, principal_due - COALESCE(principal_paid, 0))
                + GREATEST(0, interest_due - COALESCE(interest_paid, 0))
                + GREATEST(0, charges_due - COALESCE(charges_paid, 0))
                + GREATEST(0, penalty_due - COALESCE(penalty_paid, 0))
            ), 0) as total_arrears')
            ->value('total_arrears');

        // Break down arrears by component (overdue rows only — consistent basis for capitalization and waiver caps)
        $arrearComponents = LoanSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['arrears', 'partial'])
            ->where('due_date', '<', now())
            ->selectRaw('
                COALESCE(SUM(GREATEST(0, interest_due - COALESCE(interest_paid, 0))), 0)  AS interest_arrears,
                COALESCE(SUM(GREATEST(0, penalty_due  - COALESCE(penalty_paid,  0))), 0)  AS penalty_arrears,
                COALESCE(SUM(GREATEST(0, charges_due  - COALESCE(charges_paid,  0))), 0)  AS charges_arrears
            ')
            ->first();

        return [
            'outstanding_balance' => (float) $loan->outstanding_balance,
            'interest_rate' => (float) $loan->interest_rate,
            'remaining_periods' => $remainingPeriods,
            'maturity_date' => $lastSchedule?->due_date?->toDateString(),
            'term_months' => $loan->term_months,
            'arrears_amount' => round((float) $arrearsAmount, 2),
            'interest_arrears' => round((float) ($arrearComponents->interest_arrears ?? 0), 2),
            'penalty_arrears' => round((float) ($arrearComponents->penalty_arrears ?? 0), 2),
            'charges_arrears' => round((float) ($arrearComponents->charges_arrears ?? 0), 2),
        ];
    }

    private function calculateNewTerms(Loan $loan, LoanProduct $product, array $params, array $snapshot): array
    {
        $outstandingBalance = $snapshot['outstanding_balance'];

        // Determine what to capitalize.
        // outstanding_balance already contains unpaid principal (including principal arrears).
        // Only the non-principal overdue components are added on top.
        $capitalizeArrears = (bool) ($params['capitalize_arrears'] ?? false);
        $capInterest = $capitalizeArrears ? (float) $snapshot['interest_arrears'] : 0.0;
        $capPenalties = $capitalizeArrears ? (float) $snapshot['penalty_arrears'] : 0.0;
        $capCharges = $capitalizeArrears ? (float) $snapshot['charges_arrears'] : 0.0;
        $totalCapitalized = round($capInterest + $capPenalties + $capCharges, 2);

        // Determine waivers — capped at the outstanding arrear component
        $penaltiesWaived = min(
            round((float) ($params['penalties_waived'] ?? 0), 2),
            (float) $snapshot['penalty_arrears']
        );
        $interestWaived = min(
            round((float) ($params['interest_waived'] ?? 0), 2),
            (float) $snapshot['interest_arrears']
        );

        // New principal = outstanding balance + capitalized non-principal arrears − waivers
        $newPrincipal = max(0, round(
            $outstandingBalance + $totalCapitalized - $penaltiesWaived - $interestWaived,
            2
        ));

        // New rate
        $newRate = round((float) ($params['new_interest_rate'] ?? $loan->interest_rate), 2);

        // New tenor
        $newTenor = max(1, (int) ($params['new_tenor_months'] ?? $snapshot['remaining_periods']));

        return [
            'new_principal' => $newPrincipal,
            'new_rate' => $newRate,
            'new_tenor' => $newTenor,
            'capitalized_arrears' => $totalCapitalized,
            'cap_interest_arrears' => round($capInterest, 2),
            'cap_penalty_arrears' => round($capPenalties, 2),
            'cap_charges_arrears' => round($capCharges, 2),
            'capitalized_interest' => 0.0, // reserved for moratorium capitalisation
            'penalties_waived' => $penaltiesWaived,
            'interest_waived' => $interestWaived,
        ];
    }

    private function buildPreviewSchedule(array $rows, Carbon $startDate, LoanProduct $product, Loan $loan): array
    {
        $repaymentCycle = (string) ($product->repayment_cycle ?? 'monthly');
        $schedule = [];

        foreach ($rows as $row) {
            $dueDate = $this->addCycle($startDate->copy(), $repaymentCycle, (int) $row['period']);

            $schedule[] = [
                'period' => $row['period'],
                'due_date' => $dueDate->toDateString(),
                'principal' => round($row['principal'], 2),
                'interest' => round($row['interest'], 2),
                'installment' => round($row['installment'], 2),
                'balance' => round($row['balance'], 2),
            ];
        }

        return $schedule;
    }

    private function persistNewSchedule(
        Loan $loan,
        LoanProduct $product,
        LoanReschedule $rescheduleEvent,
        array $rows,
        Carbon $rescheduleDate
    ): ?string {
        $repaymentCycle = (string) ($product->repayment_cycle ?? 'monthly');

        // Determine the highest existing installment_no for this loan (paid ones stay)
        $lastPaidInstallment = LoanSchedule::where('loan_id', $loan->id)
            ->where('status', 'paid')
            ->max('installment_no') ?? 0;

        $loanSettings = LoanSetting::currentForBranch($loan->branch_id ?? 0);
        $pushHolidays = (bool) ($loanSettings->push_installments_on_holidays ?? false);

        $lastDueDate = null;

        foreach ($rows as $row) {
            $dueDate = $this->addCycle($rescheduleDate->copy(), $repaymentCycle, (int) $row['period']);

            if ($pushHolidays) {
                $dueDate = $this->holidayService->getNextWorkingDay($dueDate);
            }

            LoanSchedule::create([
                'loan_id' => $loan->id,
                'reschedule_id' => $rescheduleEvent->id,
                'installment_no' => $lastPaidInstallment + $row['period'],
                'due_date' => $dueDate,
                'principal_due' => $row['principal'],
                'interest_due' => $row['interest'],
                'total_due' => $row['installment'],
                'principal_paid' => 0,
                'interest_paid' => 0,
                'outstanding_balance' => $row['balance'],
                'status' => 'pending',
            ]);

            $lastDueDate = $dueDate->toDateString();
        }

        return $lastDueDate;
    }

    private function postRescheduleFeeAccountingEntries(
        Loan $loan,
        LoanProduct $product,
        LoanSetting $settings,
        array $feesDetail,
        Carbon $rescheduleDate,
        int $actorId,
    ): void {
        $incomeAccountId = (int) ($settings->reschedule_fee_income_account_id ?? 0);

        if (! $incomeAccountId) {
            return;
        }

        foreach ($feesDetail as $fee) {
            if ($fee['collection'] === 'capitalize') {
                if (! $product->loan_portfolio_account_id) {
                    continue;
                }

                $narration = "Reschedule fee capitalised – {$loan->loan_no} ({$fee['name']}): {$fee['amount']}";
                $this->loanAccounting->postJournalEntry(
                    loan: $loan,
                    typeCode: 'LOAN_RSC_FEE',
                    narration: $narration,
                    lines: [
                        $this->loanAccounting->line((int) $product->loan_portfolio_account_id, $fee['amount'], 0.0, $narration, $loan->id),
                        $this->loanAccounting->line($incomeAccountId, 0.0, $fee['amount'], $narration, $loan->id),
                    ],
                    date: $rescheduleDate,
                    actorId: $actorId,
                );
            } elseif ($fee['collection'] === 'savings') {
                $savingsGl = ChartOfAccount::on('tenant')
                    ->where('gl_code', GlCodes::SAVINGS_MANDATORY)
                    ->first();

                if (! $savingsGl) {
                    continue;
                }

                $savings = SavingsAccount::on('tenant')
                    ->where('member_id', $loan->member_id)
                    ->where('status', 'active')
                    ->orderByDesc('balance')
                    ->first();

                if ($savings && (float) $savings->balance >= $fee['amount']) {
                    $savings->balance = round((float) $savings->balance - $fee['amount'], 2);
                    $savings->save();

                    Transaction::create([
                        'reference' => 'RSC-FEE-'.date('Ymd').'-'.mt_rand(10000, 99999),
                        'member_id' => $loan->member_id,
                        'type' => 'charge_deduction',
                        'amount' => $fee['amount'],
                        'payment_mode' => 'system',
                        'deposited_by' => 'System (Reschedule Fee)',
                        'transaction_date' => $rescheduleDate->toDateString(),
                        'account_id' => $savings->id,
                        'account_type' => SavingsAccount::class,
                        'narration' => "Reschedule fee deducted from savings – {$loan->loan_no} ({$fee['name']})",
                        'created_by' => $actorId,
                    ]);

                    $narration = "Reschedule fee (savings) – {$loan->loan_no} ({$fee['name']}): {$fee['amount']}";
                    $this->loanAccounting->postJournalEntry(
                        loan: $loan,
                        typeCode: 'LOAN_RSC_FEE',
                        narration: $narration,
                        lines: [
                            $this->loanAccounting->line((int) $savingsGl->id, $fee['amount'], 0.0, $narration, $loan->id),
                            $this->loanAccounting->line($incomeAccountId, 0.0, $fee['amount'], $narration, $loan->id),
                        ],
                        date: $rescheduleDate,
                        actorId: $actorId,
                    );
                }
            } elseif ($fee['collection'] === 'cash') {
                if (! $product->charges_receivable_account_id) {
                    continue;
                }

                $narration = "Reschedule fee (cash) – {$loan->loan_no} ({$fee['name']}): {$fee['amount']}";
                $this->loanAccounting->postJournalEntry(
                    loan: $loan,
                    typeCode: 'LOAN_RSC_FEE',
                    narration: $narration,
                    lines: [
                        $this->loanAccounting->line((int) $product->charges_receivable_account_id, $fee['amount'], 0.0, $narration, $loan->id),
                        $this->loanAccounting->line($incomeAccountId, 0.0, $fee['amount'], $narration, $loan->id),
                    ],
                    date: $rescheduleDate,
                    actorId: $actorId,
                );
            }
        }
    }

    private function postRescheduleAccountingEntries(
        Loan $loan,
        LoanProduct $product,
        float $penaltiesWaived,
        float $interestWaived,
        float $capInterest,
        float $capPenalties,
        float $capCharges,
        Carbon $rescheduleDate,
        int $actorId,
    ): void {
        $totalCapitalized = $capInterest + $capPenalties + $capCharges;
        $hasWaivers = $penaltiesWaived > 0 || $interestWaived > 0;
        $hasCap = $totalCapitalized > 0;

        if (! $hasWaivers && ! $hasCap) {
            return;
        }

        $lines = [];
        $narrationParts = [];

        // ── Capitalisation: reclassify receivables into loan portfolio ─────────
        // DR Loan Portfolio (principal increases)
        // CR Interest/Penalty/Charges Receivable (arrears cleared)
        if ($hasCap && $product->loan_portfolio_account_id) {
            $capNarration = "Arrears capitalised – {$loan->loan_no}";

            $lines[] = $this->loanAccounting->line(
                (int) $product->loan_portfolio_account_id,
                $totalCapitalized,
                0.0,
                $capNarration,
                $loan->id,
            );

            if ($capInterest > 0 && $product->interest_receivable_account_id) {
                $lines[] = $this->loanAccounting->line(
                    (int) $product->interest_receivable_account_id,
                    0.0,
                    $capInterest,
                    $capNarration,
                    $loan->id,
                );
            }

            if ($capPenalties > 0 && $product->penalty_receivable_account_id) {
                $lines[] = $this->loanAccounting->line(
                    (int) $product->penalty_receivable_account_id,
                    0.0,
                    $capPenalties,
                    $capNarration,
                    $loan->id,
                );
            }

            if ($capCharges > 0 && $product->charges_receivable_account_id) {
                $lines[] = $this->loanAccounting->line(
                    (int) $product->charges_receivable_account_id,
                    0.0,
                    $capCharges,
                    $capNarration,
                    $loan->id,
                );
            }

            $narrationParts[] = "capitalised {$totalCapitalized}";
        }

        // ── Waiver: write off income already recognised ────────────────────────
        // DR Penalty Income / CR Penalty Receivable
        if ($penaltiesWaived > 0 && $product->penalty_income_account_id && $product->penalty_receivable_account_id) {
            $wNarration = "Penalty waiver – {$loan->loan_no}";
            $lines[] = $this->loanAccounting->line(
                (int) $product->penalty_income_account_id,
                $penaltiesWaived,
                0.0,
                $wNarration,
                $loan->id,
            );
            $lines[] = $this->loanAccounting->line(
                (int) $product->penalty_receivable_account_id,
                0.0,
                $penaltiesWaived,
                $wNarration,
                $loan->id,
            );
            $narrationParts[] = "penalty waiver {$penaltiesWaived}";
        }

        // DR Interest Income / CR Interest Receivable
        if ($interestWaived > 0 && $product->interest_income_account_id && $product->interest_receivable_account_id) {
            $wNarration = "Interest waiver – {$loan->loan_no}";
            $lines[] = $this->loanAccounting->line(
                (int) $product->interest_income_account_id,
                $interestWaived,
                0.0,
                $wNarration,
                $loan->id,
            );
            $lines[] = $this->loanAccounting->line(
                (int) $product->interest_receivable_account_id,
                0.0,
                $interestWaived,
                $wNarration,
                $loan->id,
            );
            $narrationParts[] = "interest waiver {$interestWaived}";
        }

        if (empty($lines)) {
            return;
        }

        $narration = 'Reschedule – '.$loan->loan_no.' ('.implode(', ', $narrationParts).')';

        $this->loanAccounting->postJournalEntry(
            loan: $loan,
            typeCode: 'LOAN_RSC',
            narration: $narration,
            lines: $lines,
            date: $rescheduleDate,
            actorId: $actorId,
        );
    }

    private function addCycle(Carbon $base, string $cycle, int $period): Carbon
    {
        return match ($cycle) {
            'weekly' => $base->addWeeks($period),
            'bi-weekly' => $base->addWeeks($period * 2),
            'bi-monthly' => $base->addMonths($period * 2),
            'quarterly' => $base->addMonths($period * 3),
            'annual' => $base->addYears($period),
            default => $base->addMonths($period),
        };
    }
}
