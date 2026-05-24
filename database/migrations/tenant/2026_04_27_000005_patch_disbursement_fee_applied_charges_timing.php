<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill loan_applied_charges rows whose charge is categorised as
     * 'disbursement_fee' but were incorrectly written with
     * application_timing = 'on_repayment' and used_amount = 0.
     *
     * Root cause: getChargeTiming() did not recognise 'disbursement_fee' so
     * it fell through to the on_repayment default.
     */
    public function up(): void
    {
        $table = Schema::connection('tenant')->hasTable('loan_applied_charges')
            ? 'loan_applied_charges'
            : 'loan_charges';

        // Find all applied charge rows whose source charge is a disbursement_fee
        // and fix their timing + mark them as collected (used_amount = charge_amount).
        DB::connection('tenant')->statement("
            UPDATE `{$table}` ac
            INNER JOIN `loan_charges` lc ON lc.id = ac.charge_id
            SET
                ac.application_timing = 'on_disbursement',
                ac.used_amount        = ac.charge_amount
            WHERE
                lc.category = 'disbursement_fee'
                AND ac.application_timing = 'on_repayment'
                AND ac.is_waived = 0
        ");
    }

    public function down(): void
    {
        // Non-reversible data patch.
    }
};
