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
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loans', 'disbursement_amount')) {
                $table->decimal('disbursement_amount', 15, 2)->nullable()->after('principal');
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'loan_created_by')) {
                $table->unsignedBigInteger('loan_created_by')->nullable()->after('approved_by');
                $table->foreign('loan_created_by')->references('id')->on('staff')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'loan_disbursed_by')) {
                $table->unsignedBigInteger('loan_disbursed_by')->nullable()->after('loan_created_by');
                $table->foreign('loan_disbursed_by')->references('id')->on('staff')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loans', 'loan_disbursed_by')) {
                $table->dropForeign(['loan_disbursed_by']);
                $table->dropColumn('loan_disbursed_by');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'loan_created_by')) {
                $table->dropForeign(['loan_created_by']);
                $table->dropColumn('loan_created_by');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'disbursement_amount')) {
                $table->dropColumn('disbursement_amount');
            }
        });
    }
};
