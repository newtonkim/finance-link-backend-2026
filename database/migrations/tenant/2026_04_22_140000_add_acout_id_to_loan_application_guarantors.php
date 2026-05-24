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
        Schema::connection('tenant')->table('loan_application_guarantors', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_application_guarantors', 'guarantor_account_id')) {
                $table->unsignedBigInteger('guarantor_account_id')->nullable()->after('guarantor_id')->index();

                $table->dropUnique('unique_loan_guarantor');
                $table->unique(
                    ['loan_application_id', 'guarantor_id', 'guarantor_type', 'guarantor_account_id'],
                    'unique_loan_guarantor'
                );
            }
        });
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('transactions', 'umbrella_code')) {
                $table->string('umbrella_code')->nullable()->after('code')->index()->comment('Umbrella Code i case of multiple transactions they can be under one umbrella code for easy trcking ');

             
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loan_application_guarantors', function (Blueprint $table) {
            $table->dropColumn(['guarantor_account_id']);
        });
    }
};
