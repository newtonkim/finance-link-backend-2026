<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\SubLedger;
use Carbon\Carbon;

/**
 * Canonical double-entry GL and SubLedger writer.
 *
 * Uses bcmath with scale=4 for all balance arithmetic to avoid float precision
 * drift. Uses lockForUpdate() on the balance read to prevent concurrent
 * transactions from reading a stale running balance.
 *
 * All services that write to general_ledger or sub_ledgers must use this engine
 * rather than implementing their own balance arithmetic.
 */
class GlPostingEngine
{
    public function postToGeneralLedger(
        int $jeId,
        int $accountId,
        float $debit,
        float $credit,
        Carbon|string $date,
        string $narration,
        string $normalBalance,
    ): void {
        $last = (string) (GeneralLedger::on('tenant')
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('balance') ?? '0');

        $balance = $normalBalance === 'DR'
            ? \bcadd($last, \bcsub((string) $debit, (string) $credit, 4), 4)
            : \bcadd($last, \bcsub((string) $credit, (string) $debit, 4), 4);

        GeneralLedger::create([
            'account_id' => $accountId,
            'journal_entry_id' => $jeId,
            'date' => $date instanceof Carbon ? $date->toDateString() : $date,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
            'narration' => $narration,
        ]);
    }

    public function postToSubLedger(
        int $jeId,
        int $accountId,
        int $entityId,
        string $entityType,
        float $debit,
        float $credit,
        Carbon|string $date,
        string $narration,
        string $normalBalance,
    ): void {
        $last = (string) (SubLedger::on('tenant')
            ->where('account_id', $accountId)
            ->where('entity_id', $entityId)
            ->where('entity_type', $entityType)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('balance') ?? '0');

        $balance = $normalBalance === 'DR'
            ? \bcadd($last, \bcsub((string) $debit, (string) $credit, 4), 4)
            : \bcadd($last, \bcsub((string) $credit, (string) $debit, 4), 4);

        SubLedger::create([
            'account_id' => $accountId,
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'journal_entry_id' => $jeId,
            'date' => $date instanceof Carbon ? $date->toDateString() : $date,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
            'narration' => $narration,
        ]);
    }
}
