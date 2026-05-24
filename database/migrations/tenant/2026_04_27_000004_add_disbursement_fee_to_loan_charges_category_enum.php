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

        DB::connection('tenant')->statement("
            ALTER TABLE `loan_charges`
            MODIFY COLUMN `category`
            ENUM('processing_fee','disbursement_fee','penalty','late_fee','appraisal_fee','insurance','other')
            NOT NULL DEFAULT 'other'
        ");
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_charges')) {
            return;
        }

        // Move any disbursement_fee rows to 'other' before shrinking the enum
        DB::connection('tenant')->table('loan_charges')
            ->where('category', 'disbursement_fee')
            ->update(['category' => 'other']);

        DB::connection('tenant')->statement("
            ALTER TABLE `loan_charges`
            MODIFY COLUMN `category`
            ENUM('processing_fee','penalty','late_fee','appraisal_fee','insurance','other')
            NOT NULL DEFAULT 'other'
        ");
    }
};
