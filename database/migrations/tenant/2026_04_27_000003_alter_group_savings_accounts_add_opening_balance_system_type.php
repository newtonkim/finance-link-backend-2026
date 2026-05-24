<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('group_savings_accounts', function (Blueprint $table) {
            $table->dropColumn('tenant_id');

            $table->decimal('opening_balance', 15, 2)->default(0)->after('is_new_account');

            $table->enum('system_type', ['system', 'user_created'])->default('user_created')->after('status');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('group_savings_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            $table->dropColumn('opening_balance');
            $table->dropColumn('system_type');
        });
    }
};
