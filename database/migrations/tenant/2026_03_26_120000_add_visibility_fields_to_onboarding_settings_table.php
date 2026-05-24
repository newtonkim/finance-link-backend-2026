<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('onboarding_settings', 'hide_initial_deposit_field')) {
                $table->boolean('hide_initial_deposit_field')->default(false)->after('loyal_member_min_tenure_months');
            }

            if (! Schema::connection('tenant')->hasColumn('onboarding_settings', 'hide_opening_balance_field')) {
                $table->boolean('hide_opening_balance_field')->default(false)->after('hide_initial_deposit_field');
            }

            if (! Schema::connection('tenant')->hasColumn('onboarding_settings', 'hide_is_shareholder_field')) {
                $table->boolean('hide_is_shareholder_field')->default(false)->after('hide_opening_balance_field');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            foreach ([
                'hide_initial_deposit_field',
                'hide_opening_balance_field',
                'hide_is_shareholder_field',
            ] as $column) {
                if (Schema::connection('tenant')->hasColumn('onboarding_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
