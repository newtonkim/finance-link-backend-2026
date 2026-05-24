<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Loans\Models\Loan;
use Carbon\Carbon;

/**
 * Shared accounting engine for all loan-side journal entries.
 *
 * Responsibilities:
 *  - Balanced double-entry posting (JE header + lines + GL + SubLedger)
 *  - Consistent SubLedger entity types (FQCN, not bare strings)
 *
 * JE sequence generation and GL/SubLedger writing are delegated to
 * JournalSequenceService and GlPostingEngine respectively.
 */
class LoanAccountingService
{
    public function __construct(
        private readonly JournalSequenceService $sequence,
        private readonly GlPostingEngine $gl,
    ) {}

    /**
     * Create a JE header + lines, then write to GL and SubLedger.
     *
     * Each $line array must have keys:
     *   accountId (int), debit (float), credit (float), narration (string), loanId (int)
     *
     * @param  array<array{accountId:int,debit:float,credit:float,narration:string,loanId:int}>  $lines
     */
    public function postJournalEntry(
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

            $this->gl->postToGeneralLedger(
                $je->id, $line['accountId'],
                (float) $line['debit'], (float) $line['credit'],
                $date, $line['narration'], $normalBalance,
            );

            $this->gl->postToSubLedger(
                $je->id, $line['accountId'],
                $loan->id, Loan::class,
                (float) $line['debit'], (float) $line['credit'],
                $date, $line['narration'], $normalBalance,
            );
        }

        return $je;
    }

    /**
     * Build a line array for postJournalEntry().
     */
    public function line(int $accountId, float $debit, float $credit, string $narration, int $loanId): array
    {
        return compact('accountId', 'debit', 'credit', 'narration', 'loanId');
    }
}
