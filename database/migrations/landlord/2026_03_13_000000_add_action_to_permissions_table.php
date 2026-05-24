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
        if (! Schema::connection('master')->hasColumn('permissions', 'action')) {
            Schema::connection('master')->table('permissions', function (Blueprint $table) {
                // We add it after 'id' since it's a primary identifier for permissions
                $table->string('action')->unique()->after('id')->comment("permissions name e.g., 'edit articles'")->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::connection('master')->hasColumn('permissions', 'action')) {
            Schema::connection('master')->table('permissions', function (Blueprint $table) {
                $table->dropColumn('action');
            });
        }
    }
};
