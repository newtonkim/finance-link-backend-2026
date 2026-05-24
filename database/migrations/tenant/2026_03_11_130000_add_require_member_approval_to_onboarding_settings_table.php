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
            $table->boolean('require_member_approval')->default(false)->after('auto_create_savings_account');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            $table->dropColumn('require_member_approval');
        });
    }
};
