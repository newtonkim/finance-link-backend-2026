<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_charges')) {
            return;
        }

        if (! Schema::connection('tenant')->hasColumn('loan_charges', 'category')) {
            return;
        }

        DB::connection('tenant')->statement(
            "UPDATE loan_charges SET category = 'disbursement_fee' WHERE category = 'insurance'"
        );

        DB::connection('tenant')->statement(
            "ALTER TABLE loan_charges MODIFY category ENUM('processing_fee','penalty','late_fee','appraisal_fee','disbursement_fee','other') NOT NULL"
        );
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_charges')) {
            return;
        }

        if (! Schema::connection('tenant')->hasColumn('loan_charges', 'category')) {
            return;
        }

        DB::connection('tenant')->statement(
            "UPDATE loan_charges SET category = 'insurance' WHERE category = 'disbursement_fee'"
        );

        DB::connection('tenant')->statement(
            "ALTER TABLE loan_charges MODIFY category ENUM('processing_fee','penalty','late_fee','appraisal_fee','insurance','other') NOT NULL"
        );
    }
};
