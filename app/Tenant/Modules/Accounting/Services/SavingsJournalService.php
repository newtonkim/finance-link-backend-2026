<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Contracts\SavingsCoaResolverInterface;
use App\Tenant\Modules\Accounting\GlCodes;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Support\Facades\Log;

class SavingsJournalService
{
    public function __construct(
        private readonly JournalSequenceService $sequence,
        private readonly GlPostingEngine $gl,
        private readonly SavingsCoaResolverInterface $coa,
    ) {}

    // ── Public entry points ───────────────────────────────────────────────────

    /**
     * DR Cash/Bank  CR Member Savings Liability
     */
    public function postDeposit(Transaction $transaction, SavingsAccount $account): ?JournalEntry
    {
        return $this->safe(function () use ($transaction, $account) {
            $account->loadMissing('savingsProduct');
            $cash = $this->coa->resolvePaymentModeAccount($transaction->payment_mode ?? 'bank_transfer');
            $savings = $this->coa->resolveSavingsLiabilityAccount($account);

            return $this->post(
                journalType: 'SAVINGS_DEPOSIT',
                transaction: $transaction,
                account: $account,
                narration: $transaction->narration ?? "Savings deposit – {$account->account_no}",
                lines: [
                    $this->line($cash, debit: $transaction->amount),
                    $this->line($savings, credit: $transaction->amount, memberId: $transaction->member_id, savingsId: $account->id),
                ]
            );
        });
    }

    /**
     * DR Member Savings Liability  CR Cash/Bank
     */
    public function postWithdrawal(Transaction $transaction, SavingsAccount $account): ?JournalEntry
    {
        return $this->safe(function () use ($transaction, $account) {
            $account->loadMissing('savingsProduct');
            $cash = $this->coa->resolvePaymentModeAccount($transaction->payment_mode ?? 'bank_transfer');
            $savings = $this->coa->resolveSavingsLiabilityAccount($account);

            return $this->post(
                journalType: 'SAVINGS_WITHDRAWAL',
                transaction: $transaction,
                account: $account,
                narration: $transaction->narration ?? "Savings withdrawal – {$account->account_no}",
                lines: [
                    $this->line($savings, debit: $transaction->amount, memberId: $transaction->member_id, savingsId: $account->id),
                    $this->line($cash, credit: $transaction->amount),
                ]
            );
        });
    }

    /**
     * DR Member Savings Liability  CR Fee Income
     * Uses the income GL stored on the transaction when available (from general_charge.credit_account_id),
     * otherwise falls back to the account maintenance fee GL (4230).
     */
    public function postCharge(Transaction $transaction, SavingsAccount $account): ?JournalEntry
    {
        return $this->safe(function () use ($transaction, $account) {
            $account->loadMissing('savingsProduct');
            $savings = $this->coa->resolveSavingsLiabilityAccount($account);

            $fee = $transaction->gl_credit_account_id
                ? (ChartOfAccount::on('tenant')
                    ->where('id', $transaction->gl_credit_account_id)
                    ->where('is_active', true)
                    ->first() ?? $this->coa->resolveByGlCode(GlCodes::FEE_ACCOUNT_MAINTENANCE))
                : $this->coa->resolveByGlCode(GlCodes::FEE_ACCOUNT_MAINTENANCE);

            return $this->post(
                journalType: 'SAVINGS_CHARGE',
                transaction: $transaction,
                account: $account,
                narration: $transaction->narration ?? "Savings charge - {$account->account_no}",
                lines: [
                    $this->line($savings, debit: $transaction->amount, memberId: $transaction->member_id, savingsId: $account->id),
                    $this->line($fee, credit: $transaction->amount),
                ]
            );
        });
    }

    /**
     * Opening balance migration.
     * DR Opening Balance Control (33900)  CR Member Savings Liability
     */
    public function postOpeningBalance(Transaction $transaction, SavingsAccount $account): ?JournalEntry
    {
        return $this->safe(function () use ($transaction, $account) {
            $account->loadMissing('savingsProduct');
            $retained = $this->coa->resolveByGlCode(GlCodes::OPENING_BALANCE_CONTROL);
            $savings = $this->coa->resolveSavingsLiabilityAccount($account);

            return $this->post(
                journalType: 'MIGRATION',
                transaction: $transaction,
                account: $account,
                narration: $transaction->narration ?? "Opening balance migration – {$account->account_no}",
                lines: [
                    $this->line($retained, debit: $transaction->amount),
                    $this->line($savings, credit: $transaction->amount, memberId: $transaction->member_id, savingsId: $account->id),
                ]
            );
        });
    }

    /**
     * DR Source savings liability GL  CR Destination savings liability GL
     * Journal type: SAVINGS_TRANSFER
     */
    public function postTransfer(
        SavingsAccount $fromAccount,
        SavingsAccount $toAccount,
        float $amount,
        string $reference,
        string $date,
        ?string $narration = null,
        ?int $actorId = null,
    ): ?JournalEntry {
        return $this->safe(function () use ($fromAccount, $toAccount, $amount, $reference, $date, $narration, $actorId) {
            $fromAccount->loadMissing('savingsProduct');
            $toAccount->loadMissing('savingsProduct');

            $fromGl = $this->coa->resolveSavingsLiabilityAccount($fromAccount);
            $toGl = $this->coa->resolveSavingsLiabilityAccount($toAccount);
            $note = $narration ?? "Savings transfer from {$fromAccount->account_no} to {$toAccount->account_no}";

            $je = $this->makeJe($date, $reference, $note, 'SAVINGS_TRANSFER', $actorId);

            // DR source savings liability
            $this->createLine($je, $fromGl, $amount, 0.0, $note, 1, $date, $fromAccount->member_id, $fromAccount->id);
            if ($fromAccount->member_id) {
                $this->gl->postToSubLedger(
                    $je->id, $fromGl->id, $fromAccount->member_id, Member::class,
                    $amount, 0.0, $date, $note, $fromGl->normal_balance ?? 'CR',
                );
            }

            // CR destination savings liability
            $this->createLine($je, $toGl, 0.0, $amount, $note, 2, $date, $toAccount->member_id, $toAccount->id);
            if ($toAccount->member_id) {
                $this->gl->postToSubLedger(
                    $je->id, $toGl->id, $toAccount->member_id, Member::class,
                    0.0, $amount, $date, $note, $toGl->normal_balance ?? 'CR',
                );
            }

            return $je;
        });
    }

    /**
     * DR Interest Expense on Savings  CR $creditAccount (FD Liability or Payout Savings)
     *
     * @param  ChartOfAccount  $expenseAccount  product->interestExpenseAccount
     * @param  ChartOfAccount  $creditAccount  resolved by caller based on payout type
     */
    public function postInterest(
        SavingsAccount $account,
        ChartOfAccount $expenseAccount,
        ChartOfAccount $creditAccount,
        float $interestAmount,
        string $periodStart,
        string $periodEnd,
        int $actorId,
        string $journalType = 'FD_INTEREST',
    ): ?JournalEntry {
        return $this->safe(function () use (
            $account, $expenseAccount, $creditAccount,
            $interestAmount, $periodStart, $periodEnd, $actorId, $journalType
        ) {
            $date = now()->toDateString();
            $narration = "Interest – {$account->account_no} ({$periodStart} → {$periodEnd})";

            $je = $this->makeJe($date, 'INT-'.$account->account_no, $narration, $journalType, $actorId);

            // DR Interest Expense — SubLedger: savings account entity
            $this->createLine($je, $expenseAccount, $interestAmount, 0.0, $narration, 1, $date, $account->member_id, $account->id);
            $this->gl->postToSubLedger(
                $je->id, $expenseAccount->id, $account->id, SavingsAccount::class,
                $interestAmount, 0.0, $date, $narration, $expenseAccount->normal_balance ?? 'DR',
            );

            // CR FD Liability or Payout Savings account — SubLedger: savings account entity
            $this->createLine($je, $creditAccount, 0.0, $interestAmount, $narration, 2, $date, $account->member_id, $account->id);
            $this->gl->postToSubLedger(
                $je->id, $creditAccount->id, $account->id, SavingsAccount::class,
                0.0, $interestAmount, $date, $narration, $creditAccount->normal_balance ?? 'CR',
            );

            return $je;
        });
    }

    /**
     * Transaction reversal: finds the original JE and posts its mirror.
     */
    public function postReversal(Transaction $reversal, Transaction $original, SavingsAccount $account): ?JournalEntry
    {
        return $this->safe(function () use ($reversal, $original, $account) {
            $originalJe = JournalEntry::where('reference', $original->reference)
                ->where('journal_type', '!=', 'SAVINGS_REVERSAL')
                ->latest()
                ->first();

            if ($originalJe) {
                return $this->postReversalFromJe($originalJe, $reversal);
            }

            // Fallback: reverse based on original transaction type
            $account->loadMissing('savingsProduct');

            return $original->type === 'deposit'
                ? $this->postWithdrawal($reversal, $account)
                : $this->postDeposit($reversal, $account);
        });
    }

    /**
     * DR original income GL (stored on reversal transaction)  CR Member Savings Liability
     * Used when reversing a charge transaction and the original JE cannot be found.
     */
    public function postChargeReversal(
        Transaction $reversal,
        SavingsAccount $account,
    ): ?JournalEntry {
        return $this->safe(function () use ($reversal, $account) {
            $account->loadMissing('savingsProduct');
            $date = ($reversal->transaction_date ?? now())->toDateString();

            $debitAccount = $reversal->gl_credit_account_id
                ? ChartOfAccount::on('tenant')->where('id', $reversal->gl_credit_account_id)
                    ->where('is_active', true)->where('is_postable', true)->first()
                : null;
            $debitAccount ??= $this->coa->resolveByGlCode(GlCodes::FEE_ACCOUNT_MAINTENANCE);

            $creditAccount = $this->coa->resolveSavingsLiabilityAccount($account);
            $narration = "Charge reversal – {$account->account_no} ({$reversal->reference})";

            $je = $this->makeJe($date, $reversal->reference, $narration, 'SAVINGS_REVERSAL', $reversal->created_by);

            $this->createLine($je, $debitAccount, (float) $reversal->amount, 0.0, $narration, 1, $date);

            $this->createLine($je, $creditAccount, 0.0, (float) $reversal->amount, $narration, 2, $date, $account->member_id, $account->id);
            if ($account->member_id) {
                $this->gl->postToSubLedger(
                    $je->id, $creditAccount->id, $account->member_id, Member::class,
                    0.0, (float) $reversal->amount, $date, $narration, $creditAccount->normal_balance ?? 'CR',
                );
            }

            return $je;
        });
    }

    /**
     * Mirror a posted JournalEntry: swap all DR/CR lines and mark the original as reversed.
     * Used by TransactionController and ReversalService when the original JE is found.
     */
    public function reverseJournalEntry(
        JournalEntry $original,
        string $reversalReference,
        string $date,
        ?int $reversedBy = null,
        string $narration = '',
    ): ?JournalEntry {
        return $this->safe(function () use ($original, $reversalReference, $date, $reversedBy, $narration) {
            $original->load('lines.chartOfAccount');
            $note = $narration ?: "Reversal of JE#{$original->entry_no} – {$original->reference}";
            $je = $this->makeJe($date, $reversalReference, $note, 'SAVINGS_REVERSAL', $reversedBy);

            $lineNo = 1;
            foreach ($original->lines as $orig) {
                $jel = $this->createLine(
                    $je, $orig->chartOfAccount,
                    (float) $orig->credit, (float) $orig->debit, // swap DR/CR
                    $note, $lineNo++, $date,
                    $orig->member_id, $orig->savings_id,
                );

                if ($orig->member_id) {
                    $this->gl->postToSubLedger(
                        $je->id, $jel->account_id,
                        $orig->member_id, Member::class,
                        (float) $jel->debit, (float) $jel->credit,
                        $date, $note, $orig->chartOfAccount->normal_balance ?? 'CR',
                    );
                }
            }

            $original->update([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => $reversedBy,
            ]);

            return $je;
        });
    }

    // ── Core posting engine ───────────────────────────────────────────────────

    private function post(
        string $journalType,
        Transaction $transaction,
        SavingsAccount $account,
        string $narration,
        array $lines
    ): JournalEntry {
        $date = ($transaction->transaction_date ?? now())->toDateString();
        $je = $this->makeJe($date, $transaction->reference, $narration, $journalType, $transaction->created_by);

        $lineNo = 1;
        foreach ($lines as $line) {
            $jel = JournalEntryLine::create(array_merge($line, [
                'journal_entry_id' => $je->id,
                'narration' => $narration,
                'line_no' => $lineNo++,
            ]));

            $account = ChartOfAccount::find($jel->account_id);
            $jel->setRelation('account', $account);
            $normalBalance = $account?->normal_balance ?? 'DR';

            $this->gl->postToGeneralLedger(
                $je->id, $jel->account_id,
                (float) $jel->debit, (float) $jel->credit,
                $date, $narration, $normalBalance,
            );

            if (! empty($line['member_id'])) {
                $this->gl->postToSubLedger(
                    $je->id, $jel->account_id,
                    $line['member_id'], Member::class,
                    (float) $jel->debit, (float) $jel->credit,
                    $date, $narration, $normalBalance,
                );
            }
        }

        return $je;
    }

    private function postReversalFromJe(JournalEntry $originalJe, Transaction $reversal): JournalEntry
    {
        $date = ($reversal->transaction_date ?? now())->toDateString();
        $je = $this->makeJe($date, $reversal->reference, "Reversal of {$originalJe->reference}", 'SAVINGS_REVERSAL', $reversal->created_by);

        $originalJe->load('lines.chartOfAccount');
        $lineNo = 1;
        $reversalNarration = "Reversal of {$originalJe->reference}";

        foreach ($originalJe->lines as $orig) {
            $jel = $this->createLine(
                $je, $orig->chartOfAccount,
                (float) $orig->credit, (float) $orig->debit, // swap DR/CR
                $reversalNarration, $lineNo++, $date,
                $orig->member_id, $orig->savings_id,
            );

            if ($orig->member_id) {
                $this->gl->postToSubLedger(
                    $je->id, $jel->account_id,
                    $orig->member_id, Member::class,
                    (float) $jel->debit, (float) $jel->credit,
                    $date, $reversalNarration, $orig->chartOfAccount->normal_balance ?? 'DR',
                );
            }
        }

        $originalJe->update([
            'status' => 'reversed',
            'reversed_at' => now(),
            'reversed_by' => $reversal->created_by,
        ]);

        return $je;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Create and persist a posted JournalEntry header. */
    private function makeJe(string $date, string $reference, string $narration, string $journalType, ?int $postedBy): JournalEntry
    {
        return JournalEntry::create([
            'entry_no' => $this->sequence->nextEntryNo('SAV'),
            'date' => $date,
            'period_date' => $date,
            'fiscal_period' => substr($date, 0, 7),
            'reference' => $reference,
            'narration' => $narration,
            'journal_type' => $journalType,
            'status' => 'posted',
            'is_system' => true,
            'posted_at' => now(),
            'posted_by' => $postedBy,
        ]);
    }

    /** Create a JournalEntryLine, attach its account relation, and post to GL. */
    private function createLine(
        JournalEntry $je, ChartOfAccount $account,
        float $debit, float $credit,
        string $narration, int $lineNo, string $date,
        ?int $memberId = null, ?int $savingsId = null,
    ): JournalEntryLine {
        $attrs = ['journal_entry_id' => $je->id, 'account_id' => $account->id,
            'debit' => $debit, 'credit' => $credit, 'narration' => $narration, 'line_no' => $lineNo];
        if ($memberId !== null) {
            $attrs['member_id'] = $memberId;
        }
        if ($savingsId !== null) {
            $attrs['savings_id'] = $savingsId;
        }
        $jel = JournalEntryLine::create($attrs);
        $jel->setRelation('account', $account);
        $this->gl->postToGeneralLedger(
            $je->id, $jel->account_id,
            (float) $jel->debit, (float) $jel->credit,
            $date, $narration, $account->normal_balance ?? 'DR',
        );

        return $jel;
    }

    /** Build a line array for post(). */
    private function line(
        ChartOfAccount $account,
        float $debit = 0,
        float $credit = 0,
        ?int $memberId = null,
        ?int $savingsId = null
    ): array {
        return [
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
            'member_id' => $memberId,
            'savings_id' => $savingsId,
        ];
    }

    /**
     * Wraps posting in a try/catch so a missing COA account never blocks
     * the underlying transaction from completing.
     */
    private function safe(callable $fn): ?JournalEntry
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('SavingsJournalService: could not post journal entry — '.$e->getMessage());

            return null;
        }
    }
}
