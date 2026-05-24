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
        if (Schema::connection('tenant')->hasTable('loan_settings')) {
            Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
                if (! Schema::connection('tenant')->hasColumn('loan_settings', 'push_installments_on_holidays')) {
                    $table->boolean('push_installments_on_holidays')->default(false)->after('charge_distribution_mode');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('loan_settings')) {
            Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
                if (Schema::connection('tenant')->hasColumn('loan_settings', 'push_installments_on_holidays')) {
                    $table->dropColumn('push_installments_on_holidays');
                }
            });
        }
    }
};
