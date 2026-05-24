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
            $table->decimal('balance')->nullable()->index()->after('member_id')->comment('total balance this member  as in this group its self, not regardless of accounts ');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
