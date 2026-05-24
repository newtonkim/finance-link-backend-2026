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
        Schema::table('savings_group_members', function (Blueprint $table) {
            if (! Schema::hasColumn('savings_group_members', 'group_account_id')) {
                $table->string('group_account_id')->nullable()->after('savings_group_id')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('savings_group_members', function (Blueprint $table) {
            $table->dropColumn(['group_account_id']);
        });
    }
};
