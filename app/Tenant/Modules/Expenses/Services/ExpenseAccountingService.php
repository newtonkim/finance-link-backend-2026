<?php

namespace App\Tenant\Modules\Expenses\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Models\AccountingPeriod;
use App\Tenant\Modules\Accounting\Services\GlPostingEngine;
use App\Tenant\Modules\Accounting\Services\JournalSequenceService;
use App\Tenant\Modules\Expenses\Models\Expense;
use App\Tenant\Modules\Expenses\Models\ExpenseBudget;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class ExpenseAccountingService
{
    public function __construct(
        private readonly JournalSequenceService $sequence,
        private readonly GlPostingEngine $gl,
    ) {}

    /**
     * Post an expense to the General Ledger.
     */
    public function postExpense(Expense $expense, int $actorId): JournalEntry
    {
        $date = Carbon::parse($expense->transaction_date);
        $periodCode = $date->format('Y-m');

        // 1. Enforce period lock
        $isLocked = AccountingPeriod::on('tenant')
            ->where('period_code', $periodCode)
            ->where('is_locked', true)
            ->exists();

        if ($isLocked) {
            throw ValidationException::withMessages([
                'transaction_date' => "Accounting period {$periodCode} is locked. Cannot post expense to this period.",
            ]);
        }
        
        $je = JournalEntry::create([
            'entry_no' => $this->sequence->nextEntryNo('EXP'),
            'date' => $date,
            'period_date' => $date,
            'fiscal_period' => $date->format('Y-m'),
            'journal_type' => 'expense',
            'reference' => $expense->reference_no ?? "EXP-{$expense->id}",
            'reference_type' => 'expense',
            'narration' => $expense->description ?? "Expense: {$expense->title}",
            'status' => 'posted',
            'is_system' => true,
            'posted_by' => $actorId,
            'posted_at' => now(),
            'branch_id' => $expense->branch_id,
        ]);

        // Lines:
        // 1. DEBIT the Expense Account
        // 2. CREDIT the Payment Asset Account (Bank/Cash)
        
        $lines = [
            [
                'accountId' => $expense->category->chart_of_account_id,
                'debit' => (float) $expense->amount,
                'credit' => 0,
                'narration' => "Expense: {$expense->title}",
            ],
            [
                'accountId' => $expense->chart_of_account_id,
                'debit' => 0,
                'credit' => (float) $expense->amount,
                'narration' => "Payment for: {$expense->title}",
            ]
        ];

        foreach ($lines as $lineNo => $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $je->id,
                'account_id' => $line['accountId'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'narration' => $line['narration'],
                'branch_id' => $expense->branch_id,
                'line_no' => $lineNo + 1,
            ]);

            $account = ChartOfAccount::on('tenant')->find($line['accountId']);
            $normalBalance = $account?->normal_balance ?? 'DR';

            $this->gl->postToGeneralLedger(
                $je->id, 
                $line['accountId'],
                (float) $line['debit'], 
                (float) $line['credit'],
                $date, 
                $line['narration'], 
                $normalBalance
            );
            
            // Note: We are not posting to SubLedger for general expenses yet
            // unless we want to track specific Vendors in a SubLedger.
        }

        // 3. Update budget spent counter
        $fiscalYear = $date->format('Y');

        $budget = ExpenseBudget::on('tenant')
            ->where('expense_category_id', $expense->expense_category_id)
            ->where('fiscal_year', $fiscalYear)
            ->where(function ($query) use ($periodCode) {
                $query->where('period_code', $periodCode)
                      ->orWhereNull('period_code');
            })
            ->where(function ($query) use ($expense) {
                $query->where('branch_id', $expense->branch_id)
                      ->orWhereNull('branch_id');
            })
            ->first();

        if ($budget) {
            $budget->increment('spent_amount', $expense->amount);
        }

        return $je;
    }

    /**
     * Preview the journal entry for an expense.
     */
    public function previewJournalEntry(Expense $expense): array
    {
        $debitAccount = $expense->category?->account;
        
        // Find a suitable credit account based on payment method
        $creditAccount = ChartOfAccount::on('tenant')
            ->where('code', 'LIKE', '1%')
            ->first();

        return [
            [
                'account_code' => $debitAccount?->code ?? 'N/A',
                'account_name' => $debitAccount?->name ?? 'Expense Account',
                'debit' => (float) $expense->amount,
                'credit' => 0,
                'type' => 'debit'
            ],
            [
                'account_code' => $creditAccount?->code ?? '1010',
                'account_name' => $creditAccount?->name ?? 'Bank/Cash Account',
                'debit' => 0,
                'credit' => (float) $expense->amount,
                'type' => 'credit'
            ]
        ];
    }
}
