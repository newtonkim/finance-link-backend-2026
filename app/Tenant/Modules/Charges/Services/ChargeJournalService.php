<?php

namespace App\Tenant\Modules\Charges\Services;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Contracts\SavingsCoaResolverInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Services\GlPostingEngine;
use App\Tenant\Modules\Accounting\Services\JournalSequenceService;
use App\Tenant\Modules\Charges\Contracts\ChargeJournalServiceInterface;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

class ChargeJournalService implements ChargeJournalServiceInterface
{
    public function __construct(
        private readonly JournalSequenceService $sequence,
        private readonly GlPostingEngine $gl,
        private readonly SavingsCoaResolverInterface $coa,
    ) {}

    public function post(
        GeneralCharge $charge,
        float $fee,
        int $memberId,
        SavingsAccount $savingsAccount,
        string $reference,
        int $postedBy,
    ): JournalEntry {
        $date = now()->toDateString();
        $narration = "Charge: {$charge->name} - {$savingsAccount->account_no}";

        $savingsAccount->loadMissing('savingsProduct');
        $debitAccount = $this->coa->resolveSavingsLiabilityAccount($savingsAccount);

        if (! $charge->credit_account_id) {
            throw new \DomainException("GeneralCharge #{$charge->id} has no credit_account_id configured.");
        }

        $creditAccount = ChartOfAccount::on('tenant')
            ->where('id', $charge->credit_account_id)
            ->where('is_active', true)
            ->first();

        if (! $creditAccount) {
            throw new \DomainException(
                "GL account {$charge->credit_account_id} not found or inactive for GeneralCharge #{$charge->id}."
            );
        }

        $period = substr($date, 0, 7);
        $this->ensurePeriodIsOpen($period);

        return \Illuminate\Support\Facades\DB::connection('tenant')->transaction(
            function () use ($charge, $fee, $memberId, $savingsAccount, $reference, $postedBy, $date, $narration, $debitAccount, $creditAccount, $period) {
                $je = JournalEntry::on('tenant')->create([
                    'entry_no'      => $this->sequence->nextEntryNo('CHG'),
                    'date'          => $date,
                    'period_date'   => $date,
                    'fiscal_period' => $period,
                    'reference'     => $reference,
                    'narration'     => $narration,
                    'journal_type'  => 'SAVINGS_CHARGE',
                    'status'        => 'posted',
                    'is_system'     => true,
                    'posted_at'     => now(),
                    'posted_by'     => $postedBy,
                ]);

                // DR member savings liability
                $this->writeLine($je, $debitAccount, $fee, 0.0, $narration, 1, $date, $memberId, $savingsAccount->id);

                // CR fee income
                $this->writeLine($je, $creditAccount, 0.0, $fee, $narration, 2, $date);

                return $je;
            }
        );
    }

    private function writeLine(
        JournalEntry $je,
        ChartOfAccount $account,
        float $debit,
        float $credit,
        string $narration,
        int $lineNo,
        string $date,
        ?int $memberId = null,
        ?int $savingsId = null,
    ): JournalEntryLine {
        $attrs = [
            'journal_entry_id' => $je->id,
            'account_id'       => $account->id,
            'debit'            => $debit,
            'credit'           => $credit,
            'narration'        => $narration,
            'line_no'          => $lineNo,
        ];

        if ($memberId !== null) {
            $attrs['member_id'] = $memberId;
        }

        if ($savingsId !== null) {
            $attrs['savings_id'] = $savingsId;
        }

        $line = JournalEntryLine::on('tenant')->create($attrs);

        $this->gl->postToGeneralLedger(
            $je->id,
            $account->id,
            $debit,
            $credit,
            $date,
            $narration,
            $account->normal_balance ?? 'CR',
        );

        if ($memberId !== null) {
            $this->gl->postToSubLedger(
                $je->id,
                $account->id,
                $memberId,
                Member::class,
                $debit,
                $credit,
                $date,
                $narration,
                $account->normal_balance ?? 'CR',
            );
        }

        return $line;
    }

    /**
     * Block postings into a closed (locked) fiscal period.
     * Mirrors JournalEntryService::ensurePeriodIsOpen so manual JEs and charge JEs
     * share the same rule. Silently no-ops if the table doesn't exist yet
     * (e.g. very old tenants migrated before the accounting_periods feature).
     */
    private function ensurePeriodIsOpen(?string $period): void
    {
        if (! $period || ! \Illuminate\Support\Facades\Schema::connection('tenant')->hasTable('accounting_periods')) {
            return;
        }

        $isLocked = \Illuminate\Support\Facades\DB::connection('tenant')
            ->table('accounting_periods')
            ->where('period_code', $period)
            ->where('is_locked', true)
            ->exists();

        if ($isLocked) {
            throw new \DomainException("The accounting period {$period} is closed and cannot accept new charge journal entries.");
        }
    }
}
