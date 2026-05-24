<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Accounting\GlCodes;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Services\GlPostingEngine;
use App\Tenant\Modules\Accounting\Services\JournalSequenceService;
use App\Tenant\Modules\Loans\Contracts\LoanRepaymentServiceInterface;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Models\LoanReschedule;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Modules\Loans\Models\LoanTransaction;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Settings\Models\LoanSetting;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoanRepaymentService implements LoanRepaymentServiceInterface
{
    private const ORDER_PRINCIPAL_INTEREST_PENALTIES_CHARGES = 'principal_interest_penalties_charges';

    private const ORDER_INTEREST_PRINCIPAL_PENALTIES_CHARGES = 'interest_principal_penalties_charges';

    private const ORDER_PENALTIES_CHARGES_INTEREST_PRINCIPAL = 'penalties_charges_interest_principal';

    private const ORDER_PENALTIES_CHARGES_PRINCIPAL_INTEREST = 'penalties_charges_principal_interest';

    /**
     * Backward-compatible default matching existing production behavior.
     */
    private const DEFAULT_REPAYMENT_ALLOCATION_ORDER = self::ORDER_PENALTIES_CHARGES_INTEREST_PRINCIPAL;

    /**
     * Priority map for repayment allocation buckets.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOCATION_PRIORITY = [
        self::ORDER_PRINCIPAL_INTEREST_PENALTIES_CHARGES => ['principal', 'interest', 'penalty', 'charges'],
        self::ORDER_INTEREST_PRINCIPAL_PENALTIES_CHARGES => ['interest', 'principal', 'penalty', 'charges'],
        self::ORDER_PENALTIES_CHARGES_INTEREST_PRINCIPAL => ['penalty', 'charges', 'interest', 'principal'],
        self::ORDER_PENALTIES_CHARGES_PRINCIPAL_INTEREST => ['penalty', 'charges', 'principal', 'interest'],
    ];

    public function __construct(
        protected LoanStatusGuard $statusGuard,
        protected JournalSequenceService $sequence,
        protected GlPostingEngine $gl,
    ) {}
    // ─── Public API ───────────────────────────────────────────────────────────

    public function post(Loan $loan, float $amount, array $data, int $actorId): LoanTransaction
    {
        return DB::connection('tenant')->transaction(function () use ($loan, $amount, $data, $actorId) {
            $this->assertRepayable($loan, $amount);

            $product = $loan->loanProduct()->firstOrFail();
            $schedules = $this->pendingSchedules($loan);
            $allocation = $this->allocate($loan, $amount, $schedules);

            // Step 1 — Apply amounts to each schedule row
            $this->applyToSchedules($allocation['schedules']);

            // Step 2 — Recompute outstanding balance and detect closure
            $newBalance = $this->recalculateBalance($loan);
            $isClosed = $newBalance <= 0 && $schedules->where('status', '!=', 'paid')->isEmpty();

            // Step 3 — Update loan record
            $loan->outstanding_balance = max(0, $newBalance);
            $loan->save();

            if ($isClosed) {
                $this->statusGuard->transition($loan, LoanStatus::Closed, 'Loan fully repaid.');
            }

            // Step 4 — Record the LoanTransaction
            $paymentDate = Carbon::parse($data['payment_date'] ?? now());
            $rescheduleId = $loan->is_rescheduled
                ? LoanReschedule::where('original_loan_id', $loan->id)->orderByDesc('reschedule_date')->value('id')
                : null;

            $txn = LoanTransaction::create([
                'payment_id' => 'PMT-'.strtoupper(Str::random(8)),
                'loan_id' => $loan->id,
                'reschedule_id' => $rescheduleId,
                'member_id' => $loan->member_id,
                'amount_paid' => $amount,
                'principal_portion' => $allocation['principal'],
                'interest_portion' => $allocation['interest'],
                'penalty_portion' => $allocation['penalty'],
                'charges_portion' => $allocation['charges'],
                'payment_date' => $paymentDate,
                'payment_method' => $data['payment_method'],
                'receipt_no' => $data['receipt_no'] ?? null,
                'collected_by' => $actorId,
                'transaction_ref' => $data['transaction_ref'] ?? null,
            ]);

            // Step 5 — Post accounting journal entry
            $this->postRepaymentEntry($loan, $product, $allocation, $paymentDate, $actorId);

            return $txn;
        });
    }

    public function repayFromSavings(Loan $loan, array $data, int $actorId): LoanTransaction
    {
        return DB::connection('tenant')->transaction(function () use ($loan, $data, $actorId) {
            $amount = (float) $data['amount'];
            $this->assertRepayable($loan, $amount);

            // Load savings account with product for GL code resolution
            $savingsAccount = SavingsAccount::on('tenant')
                ->with('savingsProduct')
                ->findOrFail((int) $data['savings_account_id']);

            // Re-check balance inside the transaction to prevent TOCTOU races
            if ((float) $savingsAccount->balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => ['Insufficient savings account balance.'],
                ]);
            }

            $product = $loan->loanProduct()->firstOrFail();
            $schedules = $this->pendingSchedules($loan);
            $allocation = $this->allocate($loan, $amount, $schedules);

            // Step 1 — Apply amounts to schedule rows
            $this->applyToSchedules($allocation['schedules']);

            // Step 2 — Recompute outstanding balance and detect closure
            $newBalance = $this->recalculateBalance($loan);
            $isClosed = $newBalance <= 0 && $schedules->where('status', '!=', 'paid')->isEmpty();

            // Step 3 — Update loan record
            $loan->outstanding_balance = max(0, $newBalance);
            $loan->save();

            if ($isClosed) {
                $this->statusGuard->transition($loan, LoanStatus::Closed, 'Loan fully repaid via savings.');
            }

            // Step 4 — Debit savings account balance
            $savingsAccount->balance = round((float) $savingsAccount->balance - $amount, 2);
            $savingsAccount->save();

            // Step 5 — Record savings Transaction
            $paymentDate = Carbon::parse($data['payment_date'] ?? now());
            $reference = 'SREP-'.strtoupper(Str::random(8));

            Transaction::create([
                'reference' => $reference,
                'member_id' => $loan->member_id,
                'type' => 'loan_repayment',
                'amount' => $amount,
                'payment_mode' => 'savings_account',
                'transaction_date' => $paymentDate,
                'account_id' => $savingsAccount->id,
                'account_type' => SavingsAccount::class,
                'narration' => "Loan repayment – {$loan->loan_no}",
                'created_by' => $actorId,
            ]);

            // Step 6 — Record the LoanTransaction
            $rescheduleId = $loan->is_rescheduled
                ? LoanReschedule::where('original_loan_id', $loan->id)->orderByDesc('reschedule_date')->value('id')
                : null;

            $txn = LoanTransaction::create([
                'payment_id' => 'PMT-'.strtoupper(Str::random(8)),
                'loan_id' => $loan->id,
                'reschedule_id' => $rescheduleId,
                'member_id' => $loan->member_id,
                'amount_paid' => $amount,
                'principal_portion' => $allocation['principal'],
                'interest_portion' => $allocation['interest'],
                'penalty_portion' => $allocation['penalty'],
                'charges_portion' => $allocation['charges'],
                'payment_date' => $paymentDate,
                'payment_method' => 'savings_account',
                'receipt_no' => null,
                'collected_by' => $actorId,
                'transaction_ref' => $data['notes'] ?? null,
            ]);

            // Step 7 — Post accounting journal entry
            $this->postSavingsRepaymentEntry($loan, $product, $savingsAccount, $allocation, $paymentDate, $actorId);

            return $txn;
        });
    }

    public function preview(Loan $loan, float $amount): array
    {
        $schedules = $this->pendingSchedules($loan);
        $allocation = $this->allocate($loan, $amount, $schedules);

        return [
            'total' => $amount,
            'penalty' => $allocation['penalty'],
            'charges' => $allocation['charges'],
            'interest' => $allocation['interest'],
            'principal' => $allocation['principal'],
            'overpayment' => $allocation['overpayment'],
            'schedules' => collect($allocation['schedules'])->map(fn ($s) => [
                'installment_no' => $s['schedule']->installment_no,
                'due_date' => $s['schedule']->due_date->toDateString(),
                'penalty_applied' => $s['penalty'],
                'charges_applied' => $s['charges'],
                'interest_applied' => $s['interest'],
                'principal_applied' => $s['principal'],
            ])->values()->all(),
        ];
    }

    // ─── Allocation engine ────────────────────────────────────────────────────

    /**
     * Allocate a payment across pending schedules in due_date ASC order.
     *
     * @param  Collection<LoanSchedule>  $schedules
     * @return array{penalty:float, charges:float, interest:float, principal:float, overpayment:float, schedules:array}
     */
    private function allocate(Loan $loan, float $remaining, Collection $schedules): array
    {
        $allocationOrder = $this->resolveRepaymentAllocationOrder($loan);
        $priority = self::ALLOCATION_PRIORITY[$allocationOrder];

        $totalPenalty = 0.0;
        $totalCharges = 0.0;
        $totalInterest = 0.0;
        $totalPrincipal = 0.0;
        $applied = [];

        foreach ($schedules as $schedule) {
            if ($remaining <= 0) {
                break;
            }

            $owed = [
                'penalty' => max(0, (float) $schedule->penalty_due - (float) $schedule->penalty_paid),
                'charges' => max(0, (float) $schedule->charges_due - (float) $schedule->charges_paid),
                'interest' => max(0, (float) $schedule->interest_due - (float) $schedule->interest_paid),
                'principal' => max(0, (float) $schedule->principal_due - (float) $schedule->principal_paid),
            ];

            $appliedBuckets = [
                'penalty' => 0.0,
                'charges' => 0.0,
                'interest' => 0.0,
                'principal' => 0.0,
            ];

            foreach ($priority as $bucket) {
                if ($remaining <= 0) {
                    break;
                }

                $appliedAmount = min($remaining, $owed[$bucket]);
                $appliedBuckets[$bucket] = $appliedAmount;
                $remaining -= $appliedAmount;
            }

            $penaltyApplied = $appliedBuckets['penalty'];
            $chargesApplied = $appliedBuckets['charges'];
            $interestApplied = $appliedBuckets['interest'];
            $principalApplied = $appliedBuckets['principal'];

            $totalPenalty += $penaltyApplied;
            $totalCharges += $chargesApplied;
            $totalInterest += $interestApplied;
            $totalPrincipal += $principalApplied;

            $applied[] = [
                'schedule' => $schedule,
                'penalty' => round($penaltyApplied, 2),
                'charges' => round($chargesApplied, 2),
                'interest' => round($interestApplied, 2),
                'principal' => round($principalApplied, 2),
            ];
        }

        return [
            'penalty' => round($totalPenalty, 2),
            'charges' => round($totalCharges, 2),
            'interest' => round($totalInterest, 2),
            'principal' => round($totalPrincipal, 2),
            'overpayment' => round($remaining, 2),
            'schedules' => $applied,
        ];
    }

    /**
     * Resolve the active repayment allocation order for a loan branch.
     *
     * Falls back safely to the current production behavior when setting/schema is unavailable.
     */
    private function resolveRepaymentAllocationOrder(Loan $loan): string
    {
        if (! $loan->branch_id) {
            return self::DEFAULT_REPAYMENT_ALLOCATION_ORDER;
        }

        if (! Schema::connection('tenant')->hasTable('loan_settings')
            || ! Schema::connection('tenant')->hasColumn('loan_settings', 'repayment_allocation_order')) {
            return self::DEFAULT_REPAYMENT_ALLOCATION_ORDER;
        }

        $configured = LoanSetting::query()
            ->where('branch_id', (int) $loan->branch_id)
            ->value('repayment_allocation_order');

        if (! is_string($configured) || ! array_key_exists($configured, self::ALLOCATION_PRIORITY)) {
            return self::DEFAULT_REPAYMENT_ALLOCATION_ORDER;
        }

        return $configured;
    }

    // ─── Step 1 — Apply to schedules ─────────────────────────────────────────

    private function applyToSchedules(array $applied): void
    {
        foreach ($applied as $row) {
            /** @var LoanSchedule $schedule */
            $schedule = $row['schedule'];

            $schedule->penalty_paid = round((float) $schedule->penalty_paid + $row['penalty'], 2);
            $schedule->charges_paid = round((float) $schedule->charges_paid + $row['charges'], 2);
            $schedule->interest_paid = round((float) $schedule->interest_paid + $row['interest'], 2);
            $schedule->principal_paid = round((float) $schedule->principal_paid + $row['principal'], 2);

            $totalOwed = (float) $schedule->principal_due
                + (float) $schedule->interest_due
                + (float) $schedule->penalty_due
                + (float) $schedule->charges_due;

            $totalPaid = (float) $schedule->principal_paid
                + (float) $schedule->interest_paid
                + (float) $schedule->penalty_paid
                + (float) $schedule->charges_paid;

            if ($totalPaid >= $totalOwed - 0.01) {
                $schedule->status = 'paid';
                $schedule->paid_date = now()->toDateString();
            } elseif ($totalPaid > 0) {
                $schedule->status = 'partial';
            }

            $schedule->save();
        }
    }

    // ─── Step 2 — Recalculate balance ─────────────────────────────────────────

    private function recalculateBalance(Loan $loan): float
    {
        $unpaidPrincipal = LoanSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'partial', 'arrears'])
            ->sum(DB::raw('principal_due - principal_paid'));

        return round((float) $unpaidPrincipal, 2);
    }

    // ─── Step 5 — Accounting journal entry ───────────────────────────────────

    /**
     * Repayment journal entry.
     *
     * Reducing-balance:
     *   DR  Disbursement Account (cash in)   = amount_paid
     *   CR  Loan Portfolio                   = principal_portion
     *   CR  Interest Income                  = interest_portion  (recognise on receipt)
     *   CR  Penalty Receivable               = penalty_portion   (clear the receivable)
     *   CR  Charges Receivable               = charges_portion   (clear the receivable)
     *
     * Flat-rate (interest was pre-accrued):
     *   DR  Disbursement Account             = amount_paid
     *   CR  Loan Portfolio                   = principal_portion
     *   CR  Interest Receivable              = interest_portion  (clear the receivable)
     *   CR  Penalty Receivable               = penalty_portion
     *   CR  Charges Receivable               = charges_portion
     */
    private function postRepaymentEntry(
        Loan $loan,
        LoanProduct $product,
        array $allocation,
        Carbon $paymentDate,
        int $actorId,
    ): void {
        $totalPaid = $allocation['principal'] + $allocation['interest'] + $allocation['penalty'] + $allocation['charges'];
        if ($totalPaid <= 0) {
            return;
        }

        $narration = "Loan repayment – {$loan->loan_no}";
        $isFlat = $product->interest_method === 'flat';

        $lines = [
            $this->line($product->disbursement_account_id, $totalPaid, 0.0, $narration, $loan->id),
        ];

        if ($allocation['principal'] > 0) {
            $lines[] = $this->line($product->loan_portfolio_account_id, 0.0, $allocation['principal'], $narration, $loan->id);
        }

        if ($allocation['interest'] > 0) {
            $accountId = $isFlat
                ? $product->interest_receivable_account_id
                : $product->interest_income_account_id;
            $lines[] = $this->line($accountId, 0.0, $allocation['interest'], $narration, $loan->id);
        }

        if ($allocation['penalty'] > 0) {
            $lines[] = $this->line($product->penalty_receivable_account_id, 0.0, $allocation['penalty'], $narration, $loan->id);
        }

        if ($allocation['charges'] > 0 && $product->charges_receivable_account_id) {
            $lines[] = $this->line($product->charges_receivable_account_id, 0.0, $allocation['charges'], $narration, $loan->id);
        }

        $this->postJournalEntry($loan, 'LOAN_REPAY', $narration, $lines, $paymentDate, $actorId);
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    private function assertRepayable(Loan $loan, float $amount): void
    {
        if (! in_array($loan->status, [LoanStatus::Disbursed, LoanStatus::Arrears, LoanStatus::Rescheduled], true)) {
            throw ValidationException::withMessages([
                'loan' => ['Only disbursed, arrears, or rescheduled loans accept repayments.'],
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Repayment amount must be greater than zero.'],
            ]);
        }
    }

    // ─── Journal entry primitives (mirrors LoanDisbursementService) ───────────

    private function pendingSchedules(Loan $loan): Collection
    {
        return LoanSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['pending', 'partial', 'arrears'])
            ->orderBy('due_date')
            ->get();
    }

    private function line(int $accountId, float $debit, float $credit, string $narration, int $loanId): array
    {
        return compact('accountId', 'debit', 'credit', 'narration', 'loanId');
    }

    private function postJournalEntry(
        Loan $loan,
        string $typeCode,
        string $narration,
        array $lines,
        Carbon $date,
        int $actorId,
    ): JournalEntry {
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
     * Journal entry for savings-account repayment:
     *
     *   DR  Member Savings Liability (2111/2112/2113)  = amount_paid    → SubLedger: savings_account
     *   CR  Loan Portfolio                             = principal       → SubLedger: loan
     *   CR  Interest Income / Receivable               = interest        → SubLedger: loan
     *   CR  Penalty Receivable                         = penalty         → SubLedger: loan
     *   CR  Charges Receivable                         = charges         → SubLedger: loan
     */
    private function postSavingsRepaymentEntry(
        Loan $loan,
        LoanProduct $product,
        SavingsAccount $savingsAccount,
        array $allocation,
        Carbon $paymentDate,
        int $actorId,
    ): void {
        $totalPaid = $allocation['principal'] + $allocation['interest'] + $allocation['penalty'] + $allocation['charges'];
        if ($totalPaid <= 0) {
            return;
        }

        $narration = "Loan repayment via savings – {$loan->loan_no}";
        $isFlat = $product->interest_method === 'flat';

        // Resolve the savings liability GL account (mirrors SavingsJournalService logic)
        $productType = strtolower($savingsAccount->savingsProduct?->type ?? '');
        $glCode = match (true) {
            str_contains($productType, 'mandatory') => GlCodes::SAVINGS_MANDATORY,
            str_contains($productType, 'fixed') => GlCodes::SAVINGS_FIXED_DEPOSIT,
            default => GlCodes::SAVINGS_VOLUNTARY,
        };

        $savingsLiabilityAccount = ChartOfAccount::on('tenant')
            ->where('gl_code', $glCode)
            ->where('is_active', true)
            ->first();

        if (! $savingsLiabilityAccount) {
            throw new \RuntimeException("COA entry missing for GL code {$glCode}. Run the Chart of Accounts seeder.");
        }

        $je = JournalEntry::create([
            'entry_no' => $this->sequence->nextEntryNo('SAVINGS_REPAY'),
            'date' => $paymentDate,
            'period_date' => $paymentDate,
            'fiscal_period' => $paymentDate->format('Y-m'),
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

        $lineNo = 1;

        // DR — savings liability (SubLedger entity = savings_account)
        JournalEntryLine::create([
            'journal_entry_id' => $je->id,
            'account_id' => $savingsLiabilityAccount->id,
            'debit' => $totalPaid,
            'credit' => 0.0,
            'narration' => $narration,
            'loan_id' => $loan->id,
            'member_id' => $loan->member_id,
            'branch_id' => $loan->branch_id,
            'line_no' => $lineNo++,
        ]);
        $savingsNormalBalance = $savingsLiabilityAccount->normal_balance ?? 'CR';
        $this->gl->postToGeneralLedger($je->id, $savingsLiabilityAccount->id, $totalPaid, 0.0, $paymentDate, $narration, $savingsNormalBalance);
        $this->gl->postToSubLedger($je->id, $savingsLiabilityAccount->id, $savingsAccount->id, SavingsAccount::class, $totalPaid, 0.0, $paymentDate, $narration, $savingsNormalBalance);

        // CR lines — loan accounts (SubLedger entity = loan)
        $crLines = [];

        if ($allocation['principal'] > 0) {
            $crLines[] = [$product->loan_portfolio_account_id, $allocation['principal']];
        }

        if ($allocation['interest'] > 0) {
            $accountId = $isFlat
                ? $product->interest_receivable_account_id
                : $product->interest_income_account_id;
            $crLines[] = [$accountId, $allocation['interest']];
        }

        if ($allocation['penalty'] > 0) {
            $crLines[] = [$product->penalty_receivable_account_id, $allocation['penalty']];
        }

        if ($allocation['charges'] > 0 && $product->charges_receivable_account_id) {
            $crLines[] = [$product->charges_receivable_account_id, $allocation['charges']];
        }

        foreach ($crLines as [$accountId, $crAmount]) {
            JournalEntryLine::create([
                'journal_entry_id' => $je->id,
                'account_id' => $accountId,
                'debit' => 0.0,
                'credit' => $crAmount,
                'narration' => $narration,
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'branch_id' => $loan->branch_id,
                'line_no' => $lineNo++,
            ]);
            $crAccount = ChartOfAccount::on('tenant')->find($accountId);
            $crNormalBalance = $crAccount?->normal_balance ?? 'DR';
            $this->gl->postToGeneralLedger($je->id, $accountId, 0.0, $crAmount, $paymentDate, $narration, $crNormalBalance);
            $this->gl->postToSubLedger($je->id, $accountId, $loan->id, Loan::class, 0.0, $crAmount, $paymentDate, $narration, $crNormalBalance);
        }
    }
}
