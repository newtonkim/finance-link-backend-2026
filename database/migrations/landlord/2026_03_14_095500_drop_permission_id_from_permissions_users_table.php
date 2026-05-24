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
        if (Schema::connection('master')->hasTable('permissions_users') && Schema::connection('master')->hasColumn('permissions_users', 'permission_id')) {
            Schema::connection('master')->table('permissions_users', function (Blueprint $table) {
                try {
                    $table->dropForeign('permissions_users_permission_id_foreign');
                } catch (Throwable $e) {
                    try {
                        $table->dropForeign(['permission_id']);
                    } catch (Throwable $e2) {
                        // ignore
                    }
                }
                $table->dropColumn('permission_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: we will not recreate the stray column.
    }
};
