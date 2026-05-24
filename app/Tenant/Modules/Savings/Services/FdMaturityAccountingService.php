<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Accounting\Contracts\SavingsCoaResolverInterface;
use App\Tenant\Modules\Accounting\GlCodes;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Services\GlPostingEngine;
use App\Tenant\Modules\Accounting\Services\JournalSequenceService;
use App\Tenant\Modules\Savings\Contracts\FdMaturityAccountingServiceInterface;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Support\Facades\DB;

class FdMaturityAccountingService implements FdMaturityAccountingServiceInterface
{
    public function __construct(
        private readonly JournalSequenceService $sequence,
        private readonly GlPostingEngine $gl,
        private readonly SavingsCoaResolverInterface $coa,
    ) {}

    /**
     * DR 2113 old FD sub-ledger  CR 2113 new FD sub-ledger
     * Both sides use the Fixed Deposits GL (2113); the sub-ledger distinguishes the accounts.
     */
    public function postRollover(SavingsAccount $old, SavingsAccount $new, int $actorId): JournalEntry
    {
        $amount = (string) $old->balance;

        if (bccomp($amount, '0', 2) <= 0) {
            throw new \RuntimeException("FD maturity JE skipped: balance is {$amount} on account {$old->account_no}");
        }

        return DB::connection('tenant')->transaction(function () use ($old, $new, $actorId, $amount) {
            $fdGl = $this->coa->resolveByGlCode(GlCodes::SAVINGS_FIXED_DEPOSIT);
            $date = now()->toDateString();
            $narration = "FD rollover: {$old->account_no} → {$new->account_no}";

            $je = $this->makeJe('FD_ROLLOVER', $date, $narration, $actorId);

            // DR 2113 (old FD account sub-ledger)
            $this->createLine($je, $fdGl->id, $amount, '0', $narration, 1, $date,
                $old->member_id, $old->id, $fdGl->normal_balance ?? 'CR');

            // CR 2113 (new FD account sub-ledger)
            $this->createLine($je, $fdGl->id, '0', $amount, $narration, 2, $date,
                $new->member_id, $new->id, $fdGl->normal_balance ?? 'CR');

            return $je;
        });
    }

    /**
     * DR 2113 Fixed Deposits  CR target savings GL (2111 or 2112)
     */
    public function postPayout(SavingsAccount $fd, SavingsAccount $target, int $actorId): JournalEntry
    {
        $amount = (string) $fd->balance;

        if (bccomp($amount, '0', 2) <= 0) {
            throw new \RuntimeException("FD maturity JE skipped: balance is {$amount} on account {$fd->account_no}");
        }

        return DB::connection('tenant')->transaction(function () use ($fd, $target, $actorId, $amount) {
            $fdGl = $this->coa->resolveByGlCode(GlCodes::SAVINGS_FIXED_DEPOSIT);
            $fd->loadMissing('savingsProduct');
            $target->loadMissing('savingsProduct');
            $targetGl = $this->coa->resolveSavingsLiabilityAccount($target);
            $date = now()->toDateString();
            $narration = "FD payout: {$fd->account_no} → {$target->account_no}";

            $je = $this->makeJe('FD_PAYOUT', $date, $narration, $actorId);

            // DR 2113 (old FD)
            $this->createLine($je, $fdGl->id, $amount, '0', $narration, 1, $date,
                $fd->member_id, $fd->id, $fdGl->normal_balance ?? 'CR');

            // CR target savings GL
            $this->createLine($je, $targetGl->id, '0', $amount, $narration, 2, $date,
                $target->member_id, $target->id, $targetGl->normal_balance ?? 'CR');

            return $je;
        });
    }

    /**
     * DR 2113 Fixed Deposits  CR 2112 Voluntary Savings (reclassification)
     * Must be called BEFORE account_type is updated on $fd.
     */
    public function postConversion(SavingsAccount $fd, int $actorId): JournalEntry
    {
        $amount = (string) $fd->balance;

        if (bccomp($amount, '0', 2) <= 0) {
            throw new \RuntimeException("FD maturity JE skipped: balance is {$amount} on account {$fd->account_no}");
        }

        return DB::connection('tenant')->transaction(function () use ($fd, $actorId, $amount) {
            $fdGl = $this->coa->resolveByGlCode(GlCodes::SAVINGS_FIXED_DEPOSIT);
            $volGl = $this->coa->resolveByGlCode(GlCodes::SAVINGS_VOLUNTARY);
            $date = now()->toDateString();
            $narration = "FD conversion to voluntary savings: {$fd->account_no}";

            $je = $this->makeJe('FD_CONVERSION', $date, $narration, $actorId);

            // DR 2113
            $this->createLine($je, $fdGl->id, $amount, '0', $narration, 1, $date,
                $fd->member_id, $fd->id, $fdGl->normal_balance ?? 'CR');

            // CR 2112
            $this->createLine($je, $volGl->id, '0', $amount, $narration, 2, $date,
                $fd->member_id, $fd->id, $volGl->normal_balance ?? 'CR');

            return $je;
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeJe(string $journalType, string $date, string $narration, int $postedBy): JournalEntry
    {
        return JournalEntry::create([
            'entry_no' => $this->sequence->nextEntryNo('FD'),
            'date' => $date,
            'period_date' => $date,
            'fiscal_period' => substr($date, 0, 7),
            'reference' => 'FD-'.now()->format('YmdHis'),
            'narration' => $narration,
            'journal_type' => $journalType,
            'status' => 'posted',
            'is_system' => true,
            'posted_at' => now(),
            'posted_by' => $postedBy,
        ]);
    }

    private function createLine(
        JournalEntry $je,
        int $accountId,
        string $debit,
        string $credit,
        string $narration,
        int $lineNo,
        string $date,
        ?int $memberId,
        ?int $savingsId,
        string $normalBalance,
    ): JournalEntryLine {
        $attrs = [
            'journal_entry_id' => $je->id,
            'account_id' => $accountId,
            'debit' => $debit,
            'credit' => $credit,
            'narration' => $narration,
            'line_no' => $lineNo,
        ];
        if ($memberId !== null) {
            $attrs['member_id'] = $memberId;
        }
        if ($savingsId !== null) {
            $attrs['savings_id'] = $savingsId;
        }

        $jel = JournalEntryLine::create($attrs);

        $this->gl->postToGeneralLedger(
            $je->id, $accountId,
            (float) $debit, (float) $credit,
            $date, $narration, $normalBalance,
        );

        if ($savingsId !== null) {
            $this->gl->postToSubLedger(
                $je->id, $accountId,
                $savingsId, SavingsAccount::class,
                (float) $debit, (float) $credit,
                $date, $narration, $normalBalance,
            );
        }

        return $jel;
    }
}
