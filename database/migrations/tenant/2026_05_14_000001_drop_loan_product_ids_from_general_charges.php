<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stop the lying loan-charge UI in General Charges (Gap 2).
 *
 * The general_charges table grew a loan_product_ids JSON column and an
 * `application='on_loan_application'` enum value as a half-built unification
 * with loan_charges that never landed. No loan service ever reads either
 * field — LoanDisbursementService / LoanRepaymentService / LoanRescheduleService
 * / LoanWriteOffService all consult the loan_charges definitions table directly.
 * So today any row with application=on_loan_application or non-empty
 * loan_product_ids is decorative and triggers nothing.
 *
 * This migration:
 *   1. Hard-deletes any decorative row (application=on_loan_application OR
 *      JSON_LENGTH(loan_product_ids) > 0). Output reports the deleted count.
 *   2. Drops the loan_product_ids column.
 *
 * `down()` recreates the column nullable but does NOT restore deleted rows.
 * That's intentional: the data never affected accounting behaviour, so a
 * rollback gives you a column without data on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('general_charges', 'loan_product_ids')) {
            return;
        }

        $deletedCount = DB::connection('tenant')->table('general_charges')
            ->where(function ($q) {
                $q->where('application', 'on_loan_application')
                  ->orWhereRaw('JSON_LENGTH(loan_product_ids) > 0');
            })
            ->delete();

        if ($deletedCount > 0 && isset($this->command)) {
            // Surfaces in artisan migrate output so we can confirm on rolling deploy.
            $this->command->info("  Deleted {$deletedCount} decorative general_charges rows.");
        }

        Schema::connection('tenant')->table('general_charges', function (Blueprint $table) {
            $table->dropColumn('loan_product_ids');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('general_charges', 'loan_product_ids')) {
            Schema::connection('tenant')->table('general_charges', function (Blueprint $table) {
                $table->json('loan_product_ids')->nullable()->after('credit_account_id');
            });
        }
    }
};
