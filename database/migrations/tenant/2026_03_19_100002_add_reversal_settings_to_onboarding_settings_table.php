<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            $table->boolean('reversal_requires_approval')->default(false)->after('loyal_member_min_tenure_months');
            $table->json('reversal_approver_roles')->nullable()->after('reversal_requires_approval');
            $table->unsignedSmallInteger('reversal_max_days')->default(0)->after('reversal_approver_roles')
                ->comment('0 means no limit on how many days after a transaction a reversal can be requested');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            $table->dropColumn(['reversal_requires_approval', 'reversal_approver_roles', 'reversal_max_days']);
        });
    }
};
