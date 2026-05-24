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
        Schema::table('journal_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('journal_entries', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->after('id')->nullable();
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            }
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('journal_entry_lines', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->after('id')->nullable();
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
