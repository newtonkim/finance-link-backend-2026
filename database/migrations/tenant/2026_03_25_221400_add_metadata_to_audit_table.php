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
        Schema::connection('tenant')->table('loan_status_history_audit', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_status_history_audit', 'system_type')) {
                $table->enum('system_type', ['system', 'user_created'])->default('user_created')->after('id');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_status_history_audit', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->index()->after('system_type');
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loan_status_history_audit', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_status_history_audit', 'branch_id')) {
                $table->dropForeign(['branch_id']);
                $table->dropColumn('branch_id');
            }
            if (Schema::connection('tenant')->hasColumn('loan_status_history_audit', 'system_type')) {
                $table->dropColumn('system_type');
            }
        });
    }
};
