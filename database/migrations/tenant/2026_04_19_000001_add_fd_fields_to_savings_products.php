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
        Schema::connection('tenant')->table('savings_products', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('savings_products', 'interest_rate')) {
                $table->decimal('interest_rate', 5, 4)->default(0)->after('status')
                    ->comment('Annual rate e.g. 0.1200 = 12%');
            }
            if (! Schema::connection('tenant')->hasColumn('savings_products', 'interest_payout_type')) {
                $table->enum('interest_payout_type', ['at_maturity', 'periodic_payout', 'compound'])
                    ->default('at_maturity')->after('interest_rate');
            }
            if (! Schema::connection('tenant')->hasColumn('savings_products', 'interest_posting_frequency')) {
                $table->enum('interest_posting_frequency', ['monthly', 'quarterly', 'semi_annually', 'annually'])
                    ->default('monthly')->after('interest_payout_type');
            }
            if (! Schema::connection('tenant')->hasColumn('savings_products', 'default_tenor_months')) {
                $table->unsignedInteger('default_tenor_months')->default(6)->after('interest_posting_frequency');
            }
            if (! Schema::connection('tenant')->hasColumn('savings_products', 'maturity_action')) {
                $table->enum('maturity_action', ['auto_rollover', 'manual', 'convert_to_savings'])
                    ->default('manual')->after('default_tenor_months');
            }
            if (! Schema::connection('tenant')->hasColumn('savings_products', 'convert_to_product_id')) {
                $table->unsignedBigInteger('convert_to_product_id')->nullable()->after('maturity_action');
                $table->foreign('convert_to_product_id')->references('id')->on('savings_products')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('savings_products', 'interest_expense_account_id')) {
                $table->unsignedBigInteger('interest_expense_account_id')->nullable()->after('convert_to_product_id');
            }
            if (! Schema::connection('tenant')->hasColumn('savings_products', 'interest_payable_account_id')) {
                $table->unsignedBigInteger('interest_payable_account_id')->nullable()->after('interest_expense_account_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('savings_products', function (Blueprint $table) {
            $table->dropForeign(['convert_to_product_id']);
            $table->dropColumn([
                'interest_rate', 'interest_payout_type', 'interest_posting_frequency',
                'default_tenor_months', 'maturity_action', 'convert_to_product_id',
                'interest_expense_account_id', 'interest_payable_account_id',
            ]);
        });
    }
};
