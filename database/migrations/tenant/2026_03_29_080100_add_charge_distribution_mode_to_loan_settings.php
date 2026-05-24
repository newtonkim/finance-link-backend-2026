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
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'charge_distribution_mode')) {
                $table->string('charge_distribution_mode', 30)
                    ->default('evenly')
                    ->after('auto_penalty')
                    ->comment('How on_repayment charges spread: evenly | first_installment | last_installment');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'charge_deduction_mode')) {
                $table->string('charge_deduction_mode', 30)
                    ->default('deduct_from_principal')
                    ->after('charge_distribution_mode')
                    ->comment('How on_disbursement charges are collected: deduct_from_principal | capitalize | debit_savings | pay_cash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_settings')) {
            return;
        }

        Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
            foreach (['charge_distribution_mode', 'charge_deduction_mode'] as $col) {
                if (Schema::connection('tenant')->hasColumn('loan_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
