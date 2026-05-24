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
            $table->boolean('loyalty_fee_enabled')->default(false)->after('monthly_fee_deduction_day');
            $table->enum('loyalty_adjustment_type', ['discount_percentage', 'fixed_discount', 'custom_fee'])
                ->default('discount_percentage')->after('loyalty_fee_enabled');
            $table->decimal('loyalty_adjustment_value', 15, 2)->nullable()->after('loyalty_adjustment_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('savings_products', function (Blueprint $table) {
            $table->dropColumn([
                'loyalty_fee_enabled',
                'loyalty_adjustment_type',
                'loyalty_adjustment_value',
            ]);
        });
    }
};
