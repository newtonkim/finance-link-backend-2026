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
        if (Schema::hasColumn('savings_products', 'monthly_fee_enabled')) {
            return;
        }

        Schema::table('savings_products', function (Blueprint $table) {
            $table->boolean('monthly_fee_enabled')->default(false)->after('charge_on_transfer')->index();
            $table->enum('monthly_fee_type', ['percentage', 'amount'])->nullable()->after('monthly_fee_enabled')->index();
            $table->decimal('monthly_fee_amount', 15, 2)->nullable()->after('monthly_fee_type')->index();
            $table->integer('monthly_fee_deduction_day')->nullable()->after('monthly_fee_amount')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('savings_products', function (Blueprint $table) {
            $table->dropColumn([
                'monthly_fee_enabled',
                'monthly_fee_type',
                'monthly_fee_amount',
                'monthly_fee_deduction_day',
            ]);
        });
    }
};
