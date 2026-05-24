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
        if (Schema::connection('master')->hasTable('permissions_users')) {
            Schema::connection('master')->table('permissions_users', function (Blueprint $table) {
                if (! Schema::connection('master')->hasColumn('permissions_users', 'permission_ids')) {
                    $table->json('permission_ids')->nullable()->after('user_id')->comment('json array of permission ids like [1,2,3]');
                }
                if (! Schema::connection('master')->hasColumn('permissions_users', 'created_at')) {
                    $table->timestamp('created_at')->nullable()->useCurrent();
                }
                if (! Schema::connection('master')->hasColumn('permissions_users', 'updated_at')) {
                    $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate()->index();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::connection('master')->hasTable('permissions_users')) {
            Schema::connection('master')->table('permissions_users', function (Blueprint $table) {
                if (Schema::connection('master')->hasColumn('permissions_users', 'permission_ids')) {
                    $table->dropColumn('permission_ids');
                }
                // keep timestamps if present
            });
        }
    }
};
