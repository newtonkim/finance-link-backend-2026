<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('tenant')->rename('loan_schedules', 'loan_repayment_schedule');

        Schema::connection('tenant')->table('loan_repayment_schedule', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'schedule_id')) {
                $table->string('schedule_id')->nullable()->after('id');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'charges_due')) {
                $table->decimal('charges_due', 15, 2)->default(0)->after('interest_due');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'penalty_due')) {
                $table->decimal('penalty_due', 15, 2)->default(0)->after('charges_due');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'charges_paid')) {
                $table->decimal('charges_paid', 15, 2)->default(0)->after('interest_paid');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'penalty_paid')) {
                $table->decimal('penalty_paid', 15, 2)->default(0)->after('charges_paid');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'outstanding_balance')) {
                $table->decimal('outstanding_balance', 15, 2)->default(0)->after('penalty_paid');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'paid_date')) {
                $table->date('paid_date')->nullable()->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loan_repayment_schedule', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'paid_date')) {
                $table->dropColumn('paid_date');
            }
            if (Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'outstanding_balance')) {
                $table->dropColumn('outstanding_balance');
            }
            if (Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'penalty_paid')) {
                $table->dropColumn('penalty_paid');
            }
            if (Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'charges_paid')) {
                $table->dropColumn('charges_paid');
            }
            if (Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'penalty_due')) {
                $table->dropColumn('penalty_due');
            }
            if (Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'charges_due')) {
                $table->dropColumn('charges_due');
            }
            if (Schema::connection('tenant')->hasColumn('loan_repayment_schedule', 'schedule_id')) {
                $table->dropColumn('schedule_id');
            }
        });

        Schema::connection('tenant')->rename('loan_repayment_schedule', 'loan_schedules');
    }
};
