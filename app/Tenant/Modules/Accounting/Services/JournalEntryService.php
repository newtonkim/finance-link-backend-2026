<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class JournalEntryService
{
    public function __construct(
        private readonly JournalSequenceService $sequence,
        private readonly GlPostingEngine $postingEngine,
    ) {}

    /**
     * Get paginated journal entries
     */
    public function getPaginatedEntries(int $perPage = 15, ?string $search = null, ?string $fromDate = null, ?string $toDate = null)
    {
        $query = JournalEntry::with('lines.chartOfAccount')->orderBy('date', 'desc')->orderBy('id', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('entry_no', 'like', "%{$search}%")
                    ->orWhere('narration', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        if ($fromDate) {
            $query->whereDate('date', '>=', $fromDate);
        }

        if ($toDate) {
            $query->whereDate('date', '<=', $toDate);
        }

        return $query->paginate($perPage);
    }

    public function getEntry(int $id): JournalEntry
    {
        return JournalEntry::with('lines.chartOfAccount')->findOrFail($id);
    }

    /**
     * Store a new journal entry and its lines.
     * Throws an exception if total debit != total credit.
     */
    public function createJournalEntry(array $data)
    {
        return DB::transaction(function () use ($data) {
            $totalDebit = round(collect($data['lines'])->sum('debit_amount'), 2);
            $totalCredit = round(collect($data['lines'])->sum('credit_amount'), 2);

            $entryDate = $data['entry_date'];
            $period = $data['period'] ?? date('Y-m', strtotime($entryDate));
            $status = $data['status'] ?? 'posted';
            $journalType = strtoupper($data['entry_type'] ?? 'MANUAL');

            if ($status === 'posted') {
                if (abs($totalDebit - $totalCredit) > 1e-3) {
                    throw new Exception("Total debits ({$totalDebit}) must equal total credits ({$totalCredit}).");
                }

                $this->ensurePeriodIsOpen($period);
            }

            $journalEntry = JournalEntry::create([
                'date' => $entryDate,
                'period_date' => $entryDate,
                'fiscal_period' => $period,
                'entry_no' => $this->sequence->nextEntryNo('JE'),
                'journal_type' => $journalType,
                'currency_code' => $data['currency'] ?? 'UGX',
                'reference' => $data['reference'] ?? null,
                'narration' => $data['description'] ?? null,
                'status' => $status,
                'posted_by' => $status === 'posted' ? auth()->id() ?? null : null,
                'posted_at' => $status === 'posted' ? now() : null,
                'is_system' => false,
            ]);

            // Create Lines
            foreach ($data['lines'] as $index => $lineData) {

                // Verify account allows manual entries
                $account = ChartOfAccount::findOrFail($lineData['chart_of_account_id']);
                if (! $account->allow_manual || ! $account->is_postable) {
                    throw new Exception("Account {$account->gl_code} ({$account->name}) does not allow manual journal entries or is not postable.");
                }

                $line = $journalEntry->lines()->create([
                    'account_id' => $lineData['chart_of_account_id'],
                    'narration' => $lineData['description'] ?? null,
                    'debit' => $lineData['debit_amount'] ?? 0,
                    'credit' => $lineData['credit_amount'] ?? 0,
                    'cost_centre' => $lineData['cost_centre'] ?? null,
                    'line_no' => $index + 1,
                ]);

                if ($status === 'posted') {
                    $this->postLineToLedger($journalEntry, $account, $line);
                }
            }

            return $journalEntry->load('lines');
        });
    }

    public function postDraft(JournalEntry $journalEntry): JournalEntry
    {
        return DB::transaction(function () use ($journalEntry) {
            $journalEntry = JournalEntry::with('lines.chartOfAccount')->lockForUpdate()->findOrFail($journalEntry->id);

            if ($journalEntry->status !== 'draft') {
                throw new Exception('Only draft journal entries can be posted.');
            }

            $this->ensurePeriodIsOpen($journalEntry->fiscal_period);

            $totalDebit = round((float) $journalEntry->lines->sum('debit'), 2);
            $totalCredit = round((float) $journalEntry->lines->sum('credit'), 2);

            if (abs($totalDebit - $totalCredit) > 1e-3) {
                throw new Exception("Total debits ({$totalDebit}) must equal total credits ({$totalCredit}).");
            }

            foreach ($journalEntry->lines as $line) {
                $account = $line->chartOfAccount;
                if (! $account || ! $account->allow_manual || ! $account->is_postable) {
                    throw new Exception('One or more accounts no longer allow manual journal entries.');
                }

                $this->postLineToLedger($journalEntry, $account, $line);
            }

            $journalEntry->update([
                'status' => 'posted',
                'posted_by' => auth()->id() ?? null,
                'posted_at' => now(),
            ]);

            return $journalEntry->fresh('lines.chartOfAccount');
        });
    }

    public function postDraftById(int $id): JournalEntry
    {
        return $this->postDraft(JournalEntry::findOrFail($id));
    }

    /**
     * Update a draft journal entry and its lines.
     */
    public function updateJournalEntry(int $id, array $data): JournalEntry
    {
        return DB::transaction(function () use ($id, $data) {
            $journalEntry = JournalEntry::with('lines')->lockForUpdate()->findOrFail($id);

            if ($journalEntry->status !== 'draft') {
                throw new Exception('Only draft journal entries can be updated.');
            }

            $totalDebit = round(collect($data['lines'])->sum('debit_amount'), 2);
            $totalCredit = round(collect($data['lines'])->sum('credit_amount'), 2);

            $entryDate = $data['entry_date'];
            $period = $data['period'] ?? date('Y-m', strtotime($entryDate));
            $status = $data['status'] ?? 'draft';
            $journalType = strtoupper($data['entry_type'] ?? 'MANUAL');

            if ($status === 'posted') {
                if (abs($totalDebit - $totalCredit) > 1e-3) {
                    throw new Exception("Total debits ({$totalDebit}) must equal total credits ({$totalCredit}).");
                }

                $this->ensurePeriodIsOpen($period);
            }

            $journalEntry->update([
                'date' => $entryDate,
                'period_date' => $entryDate,
                'fiscal_period' => $period,
                'journal_type' => $journalType,
                'currency_code' => $data['currency'] ?? 'UGX',
                'reference' => $data['reference'] ?? null,
                'narration' => $data['description'] ?? null,
                'status' => $status,
                'posted_by' => $status === 'posted' ? auth()->id() ?? null : $journalEntry->posted_by,
                'posted_at' => $status === 'posted' ? now() : $journalEntry->posted_at,
            ]);

            // Sync Lines: Simplest approach is delete and recreate
            $journalEntry->lines()->delete();

            foreach ($data['lines'] as $index => $lineData) {
                $account = ChartOfAccount::findOrFail($lineData['chart_of_account_id']);
                if (! $account->allow_manual || ! $account->is_postable) {
                    throw new Exception("Account {$account->gl_code} ({$account->name}) does not allow manual journal entries or is not postable.");
                }

                $line = $journalEntry->lines()->create([
                    'account_id' => $lineData['chart_of_account_id'],
                    'narration' => $lineData['description'] ?? null,
                    'debit' => $lineData['debit_amount'] ?? 0,
                    'credit' => $lineData['credit_amount'] ?? 0,
                    'cost_centre' => $lineData['cost_centre'] ?? null,
                    'line_no' => $index + 1,
                ]);

                if ($status === 'posted') {
                    $this->postLineToLedger($journalEntry, $account, $line);
                }
            }

            return $journalEntry->load('lines.chartOfAccount');
        });
    }

    /**
     * Delete a draft journal entry.
     */
    public function deleteJournalEntry(int $id): void
    {
        DB::transaction(function () use ($id) {
            $journalEntry = JournalEntry::findOrFail($id);

            if ($journalEntry->status !== 'draft') {
                throw new Exception('Only draft journal entries can be deleted.');
            }

            $journalEntry->lines()->delete();
            $journalEntry->delete();
        });
    }

    private function postLineToLedger(JournalEntry $journalEntry, ChartOfAccount $account, $line): void
    {
        $this->postingEngine->postToGeneralLedger(
            $journalEntry->id,
            $account->id,
            (float) $line->debit,
            (float) $line->credit,
            $journalEntry->date,
            $line->narration ?: $journalEntry->narration ?: 'Manual journal entry',
            $account->normal_balance,
        );
    }

    private function ensurePeriodIsOpen(?string $period): void
    {
        if (! $period || ! Schema::connection('tenant')->hasTable('accounting_periods')) {
            return;
        }

        $isLocked = DB::connection('tenant')->table('accounting_periods')
            ->where('period_code', $period)
            ->where('is_locked', true)
            ->exists();

        if ($isLocked) {
            throw new Exception("The accounting period {$period} is closed and cannot accept new journal entries.");
        }
    }
}
