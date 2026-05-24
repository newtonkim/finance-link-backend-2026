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
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loans', 'reschedule_loan_parent_id')) {
                $table->unsignedBigInteger('reschedule_loan_parent_id')->nullable();
                $table->foreign('reschedule_loan_parent_id')->references('id')->on('loans')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'closed_by_id')) {
                $table->unsignedBigInteger('closed_by_id')->nullable();
                $table->foreign('closed_by_id')->references('id')->on('staff')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'sacco_branch_id')) {
                $table->unsignedBigInteger('sacco_branch_id')->nullable();
                $table->foreign('sacco_branch_id')->references('id')->on('branches')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'loan_officer_id')) {
                $table->unsignedBigInteger('loan_officer_id')->nullable();
                $table->foreign('loan_officer_id')->references('id')->on('staff')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'assigned_to_approve')) {
                $table->unsignedBigInteger('assigned_to_approve')->nullable();
                $table->foreign('assigned_to_approve')->references('id')->on('staff')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'assigned_by_approve')) {
                $table->unsignedBigInteger('assigned_by_approve')->nullable();
                $table->foreign('assigned_by_approve')->references('id')->on('staff')->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loans', 'assigned_by_approve')) {
                $table->dropForeign(['assigned_by_approve']);
                $table->dropColumn('assigned_by_approve');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'assigned_to_approve')) {
                $table->dropForeign(['assigned_to_approve']);
                $table->dropColumn('assigned_to_approve');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'loan_officer_id')) {
                $table->dropForeign(['loan_officer_id']);
                $table->dropColumn('loan_officer_id');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'sacco_branch_id')) {
                $table->dropForeign(['sacco_branch_id']);
                $table->dropColumn('sacco_branch_id');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'closed_by_id')) {
                $table->dropForeign(['closed_by_id']);
                $table->dropColumn('closed_by_id');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'reschedule_loan_parent_id')) {
                $table->dropForeign(['reschedule_loan_parent_id']);
                $table->dropColumn('reschedule_loan_parent_id');
            }
        });
    }
};
