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
            $table->boolean('auto_create_savings_account')->default(true)->after('shares_compulsory_applies_to_existing');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            $table->dropColumn('auto_create_savings_account');
        });
    }
};
