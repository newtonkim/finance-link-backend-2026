<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The charge_id FK on loan_applied_charges was created when the table was
     * still called loan_charges and referenced general_charges(id).  After the
     * loan-charges refactor (loan_charges table is now the definitions table,
     * loan_applied_charges is the per-loan ledger), charge_id must reference
     * loan_charges(id) instead.
     *
     * The earlier nullable migration used PostgreSQL DROP CONSTRAINT syntax
     * which is silently ignored by MySQL, so the stale FK survived.
     */
    public function up(): void
    {
        $table = Schema::connection('tenant')->hasTable('loan_applied_charges')
            ? 'loan_applied_charges'
            : 'loan_charges';

        // Drop all candidate FK names using MySQL-compatible syntax.
        foreach (['loan_charges_charge_id_foreign', 'loan_applied_charges_charge_id_foreign'] as $fk) {
            try {
                DB::connection('tenant')->statement(
                    "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$fk}`"
                );
            } catch (Throwable) {
                // Constraint did not exist — safe to continue.
            }
        }

        // Re-add the FK pointing to the correct table (loan_charges definitions).
        Schema::connection('tenant')->table($table, function (Blueprint $table) {
            $table->foreign('charge_id', 'lac_charge_id_foreign')
                ->references('id')
                ->on('loan_charges')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $table = 'loan_applied_charges';

        try {
            Schema::connection('tenant')->table($table, function (Blueprint $table) {
                $table->dropForeign('lac_charge_id_foreign');
            });
        } catch (Throwable) {
        }

        // Restore original FK to general_charges
        Schema::connection('tenant')->table($table, function (Blueprint $table) {
            $table->foreign('charge_id', 'loan_charges_charge_id_foreign')
                ->references('id')
                ->on('general_charges')
                ->cascadeOnDelete();
        });
    }
};
