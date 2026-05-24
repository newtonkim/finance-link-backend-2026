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
        if (! Schema::connection('master')->hasTable('permissions_users')) {
            return;
        }

        // Drop foreign key first if it exists, then drop the column
        try {
            Schema::connection('master')->table('permissions_users', function (Blueprint $table) {
                try {
                    // Drop by explicit constraint name as seen in the error
                    $table->dropForeign('permissions_users_permission_id_foreign');
                } catch (Throwable $e) {
                    // Fallback: attempt by column
                    try {
                        $table->dropForeign(['permission_id']);
                    } catch (Throwable $e2) {
                        // Ignore if already dropped / doesn't exist
                    }
                }

                if (Schema::connection('master')->hasColumn('permissions_users', 'permission_id')) {
                    $table->dropColumn('permission_id');
                }
            });
        } catch (Throwable $e) {
            // Ignore if already aligned
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: Not restoring stray column nor foreign key
    }
};
