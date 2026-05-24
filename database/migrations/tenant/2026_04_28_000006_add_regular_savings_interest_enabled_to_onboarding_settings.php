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
            $table->boolean('regular_savings_interest_enabled')->default(false)->after('reversal_max_days');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            $table->dropColumn('regular_savings_interest_enabled');
        });
    }
};
