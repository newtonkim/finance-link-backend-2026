<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Enhance the existing loan_rescheduling table with new columns
        Schema::connection('tenant')->table('loan_rescheduling', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'reschedule_type')) {
                $table->string('reschedule_type', 50)->nullable()->after('reschedule_id')
                    ->comment('tenor_extension, rate_change, capitalization');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'old_interest_rate')) {
                $table->decimal('old_interest_rate', 5, 2)->nullable()->after('old_outstanding');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'old_remaining_periods')) {
                $table->integer('old_remaining_periods')->nullable()->after('old_interest_rate');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'old_maturity_date')) {
                $table->date('old_maturity_date')->nullable()->after('old_remaining_periods');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'new_maturity_date')) {
                $table->date('new_maturity_date')->nullable()->after('new_duration');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'capitalized_arrears')) {
                $table->decimal('capitalized_arrears', 15, 2)->default(0)->after('new_maturity_date');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'capitalized_interest')) {
                $table->decimal('capitalized_interest', 15, 2)->default(0)->after('capitalized_arrears');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'penalties_waived')) {
                $table->decimal('penalties_waived', 15, 2)->default(0)->after('capitalized_interest');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'interest_waived')) {
                $table->decimal('interest_waived', 15, 2)->default(0)->after('penalties_waived');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'performed_by')) {
                $table->unsignedBigInteger('performed_by')->nullable()->after('approved_by');
                $table->foreign('performed_by')->references('id')->on('staff')->nullOnDelete();
            }
        });

        // 2. Add reschedule tracking columns to loans table
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loans', 'is_rescheduled')) {
                $table->boolean('is_rescheduled')->default(false)->after('status');
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'reschedule_count')) {
                $table->tinyInteger('reschedule_count')->default(0)->after('is_rescheduled');
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'original_term_months')) {
                $table->integer('original_term_months')->nullable()->after('reschedule_count');
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'original_interest_rate')) {
                $table->decimal('original_interest_rate', 5, 2)->nullable()->after('original_term_months');
            }
        });

        // 3. Add reschedule_id to loan_repayment_schedule
        Schema::connection('tenant')->table('loan_repayment_schedule', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'reschedule_id')) {
                $table->unsignedBigInteger('reschedule_id')->nullable()->after('loan_id');
                $table->foreign('reschedule_id')->references('id')->on('loan_rescheduling')->nullOnDelete();
            }
        });

        // 4. Add max_reschedule_count to loan_settings
        Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'max_reschedule_count')) {
                $table->tinyInteger('max_reschedule_count')->default(3)->after('allow_reschedule');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_settings', 'max_reschedule_count')) {
                $table->dropColumn('max_reschedule_count');
            }
        });

        Schema::connection('tenant')->table('loan_repayment_schedule', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'reschedule_id')) {
                $table->dropForeign(['reschedule_id']);
                $table->dropColumn('reschedule_id');
            }
        });

        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            $columns = ['is_rescheduled', 'reschedule_count', 'original_term_months', 'original_interest_rate'];
            foreach ($columns as $col) {
                if (Schema::connection('tenant')->hasColumn('loans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::connection('tenant')->table('loan_rescheduling', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_rescheduling', 'performed_by')) {
                $table->dropForeign(['performed_by']);
                $table->dropColumn('performed_by');
            }
            $newColumns = [
                'reschedule_type', 'old_interest_rate', 'old_remaining_periods',
                'old_maturity_date', 'new_maturity_date', 'capitalized_arrears',
                'capitalized_interest', 'penalties_waived', 'interest_waived',
            ];
            foreach ($newColumns as $col) {
                if (Schema::connection('tenant')->hasColumn('loan_rescheduling', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
