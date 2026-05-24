<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_settings')) {
            return;
        }

        Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'repayment_allocation_order')) {
                $table->enum('repayment_allocation_order', [
                    'principal_interest_penalties_charges',
                    'interest_principal_penalties_charges',
                    'penalties_charges_interest_principal',
                    'penalties_charges_principal_interest',
                ])->default('penalties_charges_interest_principal')->after('charge_deduction_mode');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_settings')) {
            return;
        }

        Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_settings', 'repayment_allocation_order')) {
                $table->dropColumn('repayment_allocation_order');
            }
        });
    }
};
