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
        if (Schema::hasColumn('savings_accounts', 'custom_monthly_fee_enabled')) {
            return;
        }

        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->boolean('custom_monthly_fee_enabled')->default(false)->after('status')->index();
            $table->enum('custom_monthly_fee_type', ['percentage', 'amount'])->nullable()->after('custom_monthly_fee_enabled')->index();
            $table->decimal('custom_monthly_fee_amount', 15, 2)->nullable()->after('custom_monthly_fee_type')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'custom_monthly_fee_enabled',
                'custom_monthly_fee_type',
                'custom_monthly_fee_amount',
            ]);
        });
    }
};
