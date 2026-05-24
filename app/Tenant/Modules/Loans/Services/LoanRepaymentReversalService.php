<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Services\LoanAccountingService;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Modules\Loans\Models\LoanTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanRepaymentReversalService
{
    public function __construct(
        protected LoanAccountingService $loanAccounting,
    ) {}

    public function reverse(Loan $loan, LoanTransaction $transaction, int $actorId): void
    {
        // Validate before opening a transaction so the guard throws in all contexts
        // (including test environments that already hold an outer transaction).
        $this->assertReversible($transaction);

        DB::connection('tenant')->transaction(function () use ($loan, $transaction, $actorId) {

            $reverseDate = Carbon::now();

            $this->unapplyFromSchedule($loan, $transaction);

            $loan->increment('outstanding_balance', (float) $transaction->principal_portion);

            $this->postReversalJournalEntry($loan, $transaction, $reverseDate, $actorId);

            $transaction->update([
                'reversal_flag' => true,
                'reversed_by' => $actorId,
                'reversed_date' => $reverseDate,
            ]);
        });
    }

    private function assertReversible(LoanTransaction $transaction): void
    {
        if ($transaction->reversal_flag) {
            throw ValidationException::withMessages([
                'transaction' => ['This repayment has already been reversed.'],
            ]);
        }
    }

    private function unapplyFromSchedule(Loan $loan, LoanTransaction $transaction): void
    {
        $schedules = LoanSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['paid', 'partial'])
            ->orderByDesc('installment_no')
            ->get();

        $principalLeft = (float) $transaction->principal_portion;
        $interestLeft = (float) $transaction->interest_portion;
        $penaltyLeft = (float) $transaction->penalty_portion;
        $chargesLeft = (float) $transaction->charges_portion;

        foreach ($schedules as $schedule) {
            if ($principalLeft <= 0 && $interestLeft <= 0 && $penaltyLeft <= 0 && $chargesLeft <= 0) {
                break;
            }

            $principalUndo = min($principalLeft, (float) $schedule->principal_paid);
            $interestUndo = min($interestLeft, (float) $schedule->interest_paid);
            $penaltyUndo = min($penaltyLeft, (float) ($schedule->penalty_paid ?? 0));
            $chargesUndo = min($chargesLeft, (float) ($schedule->charges_paid ?? 0));

            $schedule->decrement('principal_paid', $principalUndo);
            $schedule->decrement('interest_paid', $interestUndo);
            if ($penaltyUndo > 0) {
                $schedule->decrement('penalty_paid', $penaltyUndo);
            }
            if ($chargesUndo > 0) {
                $schedule->decrement('charges_paid', $chargesUndo);
            }

            $freshSchedule = $schedule->fresh();
            $totalPaid = (float) $freshSchedule->principal_paid + (float) $freshSchedule->interest_paid;
            $totalDue = (float) $freshSchedule->principal_due + (float) $freshSchedule->interest_due;

            $newStatus = match (true) {
                $totalPaid <= 0 => 'pending',
                $totalPaid >= $totalDue => 'paid',
                $freshSchedule->due_date->isPast() => 'arrears',
                default => 'partial',
            };

            $schedule->update(['status' => $newStatus]);

            $principalLeft -= $principalUndo;
            $interestLeft -= $interestUndo;
            $penaltyLeft -= $penaltyUndo;
            $chargesLeft -= $chargesUndo;
        }
    }

    private function postReversalJournalEntry(
        Loan $loan,
        LoanTransaction $transaction,
        Carbon $reverseDate,
        int $actorId,
    ): void {
        $originalJe = JournalEntry::on('tenant')
            ->where('reference', $loan->loan_no)
            ->where('journal_type', 'loan')
            ->whereDate('date', $transaction->payment_date)
            ->where('status', 'posted')
            ->latest()
            ->first();

        if ($originalJe) {
            $originalJe->load('lines');
            $mirrorLines = $originalJe->lines->map(function ($line) use ($loan) {
                return $this->loanAccounting->line(
                    $line->account_id,
                    (float) $line->credit,
                    (float) $line->debit,
                    "Reversal: {$line->narration}",
                    $loan->id,
                );
            })->values()->toArray();

            $this->loanAccounting->postJournalEntry(
                loan: $loan,
                typeCode: 'LOAN_REV',
                narration: "Repayment reversal – {$loan->loan_no}",
                lines: $mirrorLines,
                date: $reverseDate,
                actorId: $actorId,
            );

            $originalJe->update(['status' => 'reversed']);

            return;
        }

        // Fallback: synthetic reversal when no matching JE is found.
        $loan->loadMissing('loanProduct');
        $product = $loan->loanProduct;
        if (! $product) {
            return;
        }

        $totalPaid = (float) $transaction->amount_paid;
        $isFlat = $product->interest_method === 'flat';

        $lines = [
            $this->loanAccounting->line(
                (int) $product->disbursement_account_id,
                0.0,
                $totalPaid,
                "Repayment reversal – {$loan->loan_no}",
                $loan->id,
            ),
        ];

        if ((float) $transaction->principal_portion > 0) {
            $lines[] = $this->loanAccounting->line(
                (int) $product->loan_portfolio_account_id,
                (float) $transaction->principal_portion,
                0.0,
                "Principal reversed – {$loan->loan_no}",
                $loan->id,
            );
        }
        if ((float) $transaction->interest_portion > 0) {
            $accId = $isFlat
                ? (int) $product->interest_receivable_account_id
                : (int) $product->interest_income_account_id;
            $lines[] = $this->loanAccounting->line(
                $accId,
                (float) $transaction->interest_portion,
                0.0,
                "Interest reversed – {$loan->loan_no}",
                $loan->id,
            );
        }
        if ((float) $transaction->penalty_portion > 0 && $product->penalty_receivable_account_id) {
            $lines[] = $this->loanAccounting->line(
                (int) $product->penalty_receivable_account_id,
                (float) $transaction->penalty_portion,
                0.0,
                "Penalty reversed – {$loan->loan_no}",
                $loan->id,
            );
        }
        if ((float) $transaction->charges_portion > 0 && $product->charges_receivable_account_id) {
            $lines[] = $this->loanAccounting->line(
                (int) $product->charges_receivable_account_id,
                (float) $transaction->charges_portion,
                0.0,
                "Charges reversed – {$loan->loan_no}",
                $loan->id,
            );
        }

        $this->loanAccounting->postJournalEntry(
            loan: $loan,
            typeCode: 'LOAN_REV',
            narration: "Repayment reversal (synthetic) – {$loan->loan_no}",
            lines: $lines,
            date: $reverseDate,
            actorId: $actorId,
        );
    }
}
