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
        Schema::connection('tenant')->table('loan_charges', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_charges', 'charge_type')) {
                $table->enum('charge_type', ['flat', 'percentage'])->default('flat')->after('name');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_charges', 'application_timing')) {
                $table->enum('application_timing', ['on_disbursement', 'on_repayment', 'monthly'])->default('on_disbursement')->after('charge_type');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_charges', 'is_waived')) {
                $table->boolean('is_waived')->default(false)->after('used_amount');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_charges', 'waived_by')) {
                $table->unsignedBigInteger('waived_by')->nullable()->after('is_waived');
                $table->foreign('waived_by')->references('id')->on('staff')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loan_charges', 'waived_date')) {
                $table->dateTime('waived_date')->nullable()->after('waived_by');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_charges', 'waiver_reason')) {
                $table->text('waiver_reason')->nullable()->after('waived_date');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_charges', 'is_mandatory')) {
                $table->boolean('is_mandatory')->default(false)->after('waiver_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loan_charges', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_charges', 'waived_by')) {
                $table->dropForeign(['waived_by']);
                $table->dropColumn('waived_by');
            }
            if (Schema::connection('tenant')->hasColumn('loan_charges', 'charge_type')) {
                $table->dropColumn('charge_type');
            }
            if (Schema::connection('tenant')->hasColumn('loan_charges', 'application_timing')) {
                $table->dropColumn('application_timing');
            }
            if (Schema::connection('tenant')->hasColumn('loan_charges', 'is_waived')) {
                $table->dropColumn('is_waived');
            }
            if (Schema::connection('tenant')->hasColumn('loan_charges', 'waived_date')) {
                $table->dropColumn('waived_date');
            }
            if (Schema::connection('tenant')->hasColumn('loan_charges', 'waiver_reason')) {
                $table->dropColumn('waiver_reason');
            }
            if (Schema::connection('tenant')->hasColumn('loan_charges', 'is_mandatory')) {
                $table->dropColumn('is_mandatory');
            }
        });
    }
};
