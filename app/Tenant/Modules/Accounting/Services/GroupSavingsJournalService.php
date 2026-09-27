<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Contracts\SavingsCoaResolverInterface;
use App\Tenant\Modules\Accounting\GlCodes;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Puts group savings in the general ledger, as SavingsJournalService does for
 * members' own savings. Group savings are owed to the group, so they sit in their
 * own liability account (21104, Group Savings Deposits), with each group savings
 * account as the sub-ledger entity.
 *
 * A group account is journaled only once it is "on the ledger" (gl_opened_at set):
 * its balance at that moment is posted as an opening balance, and every movement
 * after it is journaled. That way an account's history is never counted twice.
 * Accounts created from now on start on the ledger; existing ones are brought on
 * by openLedger(), from the migration or the group-savings:open-ledger command.
 */
class GroupSavingsJournalService
{
    /** Sub-ledger entity type for a group savings account (it has no model class). */
    public const ENTITY = 'group_savings_account';

    public function __construct(
        private readonly JournalSequenceService $sequence,
        private readonly GlPostingEngine $gl,
        private readonly SavingsCoaResolverInterface $coa,
    ) {}

    public function liabilityAccount(): ChartOfAccount
    {
        return $this->coa->resolveByGlCode(GlCodes::SAVINGS_GROUP);
    }

    public function isOnLedger(int $groupAccountId): bool
    {
        return DB::connection('tenant')->table('group_savings_accounts')
            ->where('id', $groupAccountId)
            ->whereNotNull('gl_opened_at')
            ->exists();
    }

    /**
     * Money paid into a group account. The group is credited the amount less any
     * charge, which goes to fee income.
     *
     * DR Cash/Bank (amount)  CR Group Savings (amount − charge)  CR Fee Income (charge)
     */
    public function postDeposit(
        int $groupAccountId,
        float $amount,
        float $charge,
        ?int $cashAccountId,
        ?string $paymentMode,
        string $narration,
        ?string $reference = null,
        ?string $date = null,
        ?int $actorId = null
    ): ?JournalEntry {
        return $this->whenOnLedger($groupAccountId, function () use ($groupAccountId, $amount, $charge, $cashAccountId, $paymentMode, $narration, $reference, $date, $actorId) {
            $charge = min(max($charge, 0), $amount);
            $lines = [
                [$this->cashAccount($cashAccountId, $paymentMode), $amount, 0.0, false],
                [$this->liabilityAccount(), 0.0, round($amount - $charge, 2), true],
            ];
            if ($charge > 0) {
                $lines[] = [$this->feeAccount(), 0.0, $charge, false];
            }

            return $this->post('GROUP_SAVINGS_DEPOSIT', $groupAccountId, $lines, $narration, $reference, $date, $actorId);
        });
    }

    /**
     * Money paid out of a group account. The group is debited the amount plus any
     * charge; the charge goes to fee income.
     *
     * DR Group Savings (amount + charge)  CR Cash/Bank (amount)  CR Fee Income (charge)
     */
    public function postWithdrawal(
        int $groupAccountId,
        float $amount,
        float $charge,
        ?int $cashAccountId,
        ?string $paymentMode,
        string $narration,
        ?string $reference = null,
        ?string $date = null,
        ?int $actorId = null
    ): ?JournalEntry {
        return $this->whenOnLedger($groupAccountId, function () use ($groupAccountId, $amount, $charge, $cashAccountId, $paymentMode, $narration, $reference, $date, $actorId) {
            $charge = max($charge, 0);
            $lines = [
                [$this->liabilityAccount(), round($amount + $charge, 2), 0.0, true],
                [$this->cashAccount($cashAccountId, $paymentMode), 0.0, $amount, false],
            ];
            if ($charge > 0) {
                $lines[] = [$this->feeAccount(), 0.0, $charge, false];
            }

            return $this->post('GROUP_SAVINGS_WITHDRAWAL', $groupAccountId, $lines, $narration, $reference, $date, $actorId);
        });
    }

    /**
     * Put a group account on the ledger: post its balance as an opening balance
     * (DR Opening Balance Control, CR Group Savings) and mark it, so movements from
     * now on are journaled. Does nothing for an account already on the ledger.
     *
     * @return bool whether the account is now on the ledger
     */
    public function openLedger(int $groupAccountId, ?int $actorId = null): bool
    {
        return DB::connection('tenant')->transaction(function () use ($groupAccountId, $actorId) {
            $account = DB::connection('tenant')->table('group_savings_accounts')
                ->where('id', $groupAccountId)
                ->lockForUpdate()
                ->first(['id', 'balance', 'gl_opened_at']);

            if (! $account) {
                return false;
            }
            if ($account->gl_opened_at) {
                return true;
            }

            $balance = round((float) $account->balance, 2);
            if ($balance != 0.0) {
                $opening = $this->coa->resolveByGlCode(GlCodes::OPENING_BALANCE_CONTROL);
                $lines = $balance > 0
                    ? [[$opening, $balance, 0.0, false], [$this->liabilityAccount(), 0.0, $balance, true]]
                    : [[$this->liabilityAccount(), -$balance, 0.0, true], [$opening, 0.0, -$balance, false]];

                $this->post('GROUP_SAVINGS_OPENING', $groupAccountId, $lines, 'Group savings brought onto the ledger', null, null, $actorId);
            }

            DB::connection('tenant')->table('group_savings_accounts')
                ->where('id', $groupAccountId)
                ->update(['gl_opened_at' => now()]);

            return true;
        });
    }

    /**
     * Put a brand-new group account on the ledger with its initial deposit, which is
     * cash paid in rather than a balance brought over. Throws if it cannot be posted,
     * leaving the account off the ledger (openLedger can bring it on later).
     */
    public function openWithDeposit(int $groupAccountId, float $deposit, ?int $cashAccountId, ?int $actorId = null): void
    {
        DB::connection('tenant')->transaction(function () use ($groupAccountId, $deposit, $cashAccountId, $actorId) {
            if ($deposit > 0) {
                $this->post('GROUP_SAVINGS_DEPOSIT', $groupAccountId, [
                    [$this->cashAccount($cashAccountId, null), $deposit, 0.0, false],
                    [$this->liabilityAccount(), 0.0, $deposit, true],
                ], 'Group savings initial deposit', null, null, $actorId);
            }

            DB::connection('tenant')->table('group_savings_accounts')
                ->where('id', $groupAccountId)
                ->update(['gl_opened_at' => now()]);
        });
    }

    /**
     * Put every group account not yet on the ledger onto it. An account whose
     * chart of accounts is missing an account it needs is left off and reported.
     *
     * @return array{opened: int, failed: list<array{id: int, error: string}>}
     */
    public function openAllLedgers(?int $actorId = null): array
    {
        $opened = 0;
        $failed = [];

        $ids = DB::connection('tenant')->table('group_savings_accounts')
            ->whereNull('gl_opened_at')
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                if ($this->openLedger((int) $id, $actorId)) {
                    $opened++;
                }
            } catch (\Throwable $e) {
                $failed[] = ['id' => (int) $id, 'error' => $e->getMessage()];
            }
        }

        return ['opened' => $opened, 'failed' => $failed];
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Journal only accounts on the ledger, and never let a posting problem undo the
     * money movement itself (the same stance as SavingsJournalService).
     */
    private function whenOnLedger(int $groupAccountId, callable $fn): ?JournalEntry
    {
        if (! $this->isOnLedger($groupAccountId)) {
            return null;
        }

        try {
            // A savepoint, so a posting that fails half way leaves no stray lines.
            return DB::connection('tenant')->transaction($fn);
        } catch (\Throwable $e) {
            Log::warning('GroupSavingsJournalService: could not post journal entry — '.$e->getMessage(), [
                'group_savings_account_id' => $groupAccountId,
            ]);

            return null;
        }
    }

    private function cashAccount(?int $cashAccountId, ?string $paymentMode): ChartOfAccount
    {
        if ($cashAccountId) {
            $account = ChartOfAccount::on('tenant')->where('id', $cashAccountId)->where('is_active', true)->first();
            if ($account) {
                return $account;
            }
        }

        return $this->coa->resolvePaymentModeAccount($paymentMode ?: 'cash');
    }

    private function feeAccount(): ChartOfAccount
    {
        return $this->coa->resolveByGlCode(GlCodes::FEE_ACCOUNT_MAINTENANCE);
    }

    /**
     * @param  list<array{0: ChartOfAccount, 1: float, 2: float, 3: bool}>  $lines  account, debit, credit, is the group's own line
     */
    private function post(string $type, int $groupAccountId, array $lines, string $narration, ?string $reference, ?string $date, ?int $actorId): JournalEntry
    {
        $date = Carbon::parse($date ?? now());
        $branchId = DB::connection('tenant')->table('group_savings_accounts')->where('id', $groupAccountId)->value('branch_id');

        $je = JournalEntry::create([
            'entry_no' => $this->sequence->nextEntryNo($type),
            'date' => $date,
            'period_date' => $date,
            'fiscal_period' => $date->format('Y-m'),
            'journal_type' => 'savings',
            'reference' => $reference ?? "GSA-{$groupAccountId}",
            'reference_type' => self::ENTITY,
            'narration' => $narration,
            'status' => 'posted',
            'is_system' => true,
            'posted_by' => $actorId ?? auth()->id(),
            'posted_at' => now(),
            'branch_id' => $branchId,
        ]);

        $lineNo = 1;
        foreach ($lines as [$account, $debit, $credit, $isGroupLine]) {
            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            JournalEntryLine::create([
                'journal_entry_id' => $je->id,
                'account_id' => $account->id,
                'debit' => $debit,
                'credit' => $credit,
                'narration' => $narration,
                'branch_id' => $branchId,
                'line_no' => $lineNo++,
            ]);

            $normal = $account->normal_balance ?? 'DR';
            $this->gl->postToGeneralLedger($je->id, $account->id, $debit, $credit, $date, $narration, $normal);

            if ($isGroupLine) {
                $this->gl->postToSubLedger($je->id, $account->id, $groupAccountId, self::ENTITY, $debit, $credit, $date, $narration, $normal);
            }
        }

        return $je;
    }
}
