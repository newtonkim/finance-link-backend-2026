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
        $hasDomain = Schema::connection('master')->hasColumn('tenants', 'domain');
        $hasSettings = Schema::connection('master')->hasColumn('tenants', 'settings');
        $hasDeletedAt = Schema::connection('master')->hasColumn('tenants', 'deleted_at');

        Schema::connection('master')->table('tenants', function (Blueprint $table) use ($hasDomain, $hasSettings, $hasDeletedAt) {
            if (! $hasDomain) {
                $table->string('domain')->nullable()->unique()->after('subdomain');
            }
            if (! $hasSettings) {
                $table->json('settings')->nullable()->after('status');
            }
            if (! $hasDeletedAt) {
                $table->softDeletes();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasDomain = Schema::connection('master')->hasColumn('tenants', 'domain');
        $hasSettings = Schema::connection('master')->hasColumn('tenants', 'settings');
        $hasDeletedAt = Schema::connection('master')->hasColumn('tenants', 'deleted_at');

        Schema::connection('master')->table('tenants', function (Blueprint $table) use ($hasDomain, $hasSettings, $hasDeletedAt) {
            if ($hasDomain) {
                $table->dropUnique(['domain']);
                $table->dropColumn('domain');
            }
            if ($hasSettings) {
                $table->dropColumn('settings');
            }
            if ($hasDeletedAt) {
                $table->dropSoftDeletes();
            }
        });
    }
};
