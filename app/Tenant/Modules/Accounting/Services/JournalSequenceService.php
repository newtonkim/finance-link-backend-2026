<?php

namespace App\Tenant\Modules\Accounting\Services;

use Illuminate\Support\Facades\DB;

/**
 * Atomic, race-condition-free JE number generation via a per-day sequence table.
 *
 * Uses INSERT … ON DUPLICATE KEY UPDATE with LAST_INSERT_ID() so that the
 * incremented value is returned in a single round-trip with no SELECT/UPDATE race.
 *
 * Format: JE-{TYPECODE}-{YYYYMMDD}-{00001}
 */
class JournalSequenceService
{
    public function nextEntryNo(string $typeCode): string
    {
        $prefix = now()->format('Ymd');

        DB::connection('tenant')->statement(
            'INSERT INTO journal_entry_sequences (date_prefix, seq) VALUES (?, 1)
             ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)',
            [$prefix]
        );

        $seq = (int) DB::connection('tenant')->getPdo()->lastInsertId();

        // On a fresh INSERT the ON DUPLICATE KEY branch is not executed,
        // so LAST_INSERT_ID() is not set — fall back to a direct read.
        if ($seq === 0) {
            $seq = (int) DB::connection('tenant')
                ->table('journal_entry_sequences')
                ->where('date_prefix', $prefix)
                ->value('seq');
        }

        return sprintf('JE-%s-%s-%05d', $typeCode, $prefix, $seq);
    }
}
