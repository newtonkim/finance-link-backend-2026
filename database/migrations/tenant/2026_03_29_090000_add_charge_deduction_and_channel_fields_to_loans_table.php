<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('loans')) {
            return;
        }

        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loans', 'total_charges_deducted')) {
                $table->decimal('total_charges_deducted', 15, 2)->default(0)->after('processing_fee');
            }

            if (! Schema::connection('tenant')->hasColumn('loans', 'charge_deduction_mode')) {
                $table->string('charge_deduction_mode', 30)->nullable()->after('disbursement_reference');
            }

            if (! Schema::connection('tenant')->hasColumn('loans', 'charge_receipt_no')) {
                $table->string('charge_receipt_no', 50)->nullable()->after('charge_deduction_mode');
            }

            if (! Schema::connection('tenant')->hasColumn('loans', 'savings_account_id')) {
                $table->unsignedBigInteger('savings_account_id')->nullable()->after('charge_receipt_no');
            }

            if (! Schema::connection('tenant')->hasColumn('loans', 'mobile_money_provider')) {
                $table->string('mobile_money_provider', 20)->nullable()->after('savings_account_id');
            }

            if (! Schema::connection('tenant')->hasColumn('loans', 'mobile_money_number')) {
                $table->string('mobile_money_number', 20)->nullable()->after('mobile_money_provider');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('loans')) {
            return;
        }

        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            foreach ([
                'total_charges_deducted',
                'charge_deduction_mode',
                'charge_receipt_no',
                'savings_account_id',
                'mobile_money_provider',
                'mobile_money_number',
            ] as $col) {
                if (Schema::connection('tenant')->hasColumn('loans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
