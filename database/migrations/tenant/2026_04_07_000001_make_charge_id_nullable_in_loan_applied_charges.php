<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = Schema::connection('tenant')->hasTable('loan_applied_charges')
            ? 'loan_applied_charges'
            : 'loan_charges';

        // Attempt to drop the FK by both possible names (table was renamed from loan_charges).
        // Silently continue if the constraint doesn't exist.
        foreach (['loan_charges_charge_id_foreign', 'loan_applied_charges_charge_id_foreign'] as $fkName) {
            try {
                DB::connection('tenant')->statement(
                    "ALTER TABLE \"{$tableName}\" DROP CONSTRAINT IF EXISTS \"{$fkName}\""
                );
            } catch (Throwable $e) {
                // Constraint didn't exist — safe to continue
            }
        }

        Schema::connection('tenant')->table($tableName, function (Blueprint $table) {
            $table->unsignedBigInteger('charge_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Non-reversible: restoring NOT NULL could break existing null rows.
    }
};
