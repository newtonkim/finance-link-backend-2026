<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('staff', 'is_tenant_admin')) {
            return;
        }

        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('is_tenant_admin')->default(false)->after('role')->index();
            $table->string('status')->default('active')->after('is_tenant_admin')->index();
            $table->text('two_factor_secret')->nullable()->after('remember_token');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes')->index();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['is_tenant_admin', 'status', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
            $table->dropSoftDeletes();
        });
    }
};
