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
        if (! Schema::connection('tenant')->hasTable('loan_settings')) {
            return;
        }

        Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'push_installments_on_holidays_weekdays_only')) {
                $table->boolean('push_installments_on_holidays_weekdays_only')
                    ->default(false)
                    ->after('push_installments_on_holidays');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'relative_scheduling')) {
                $table->boolean('relative_scheduling')
                    ->default(false)
                    ->after('push_installments_on_holidays_weekdays_only');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_settings')) {
            return;
        }

        Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_settings', 'relative_scheduling')) {
                $table->dropColumn('relative_scheduling');
            }

            if (Schema::connection('tenant')->hasColumn('loan_settings', 'push_installments_on_holidays_weekdays_only')) {
                $table->dropColumn('push_installments_on_holidays_weekdays_only');
            }
        });
    }
};
