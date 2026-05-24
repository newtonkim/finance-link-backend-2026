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
        if (! Schema::connection('tenant')->hasTable('loan_approvals')) {
            return;
        }

        Schema::connection('tenant')->table('loan_approvals', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_approvals', 'approval_id')) {
                $table->string('approval_id')->nullable()->after('id');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_approvals', 'approver_id')) {
                $table->unsignedBigInteger('approver_id')->nullable()->after('loan_id');
                $table->foreign('approver_id')->references('id')->on('staff')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loan_approvals', 'approval_level')) {
                $table->integer('approval_level')->nullable()->after('approver_id');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_approvals', 'status')) {
                $table->string('status')->nullable()->after('action');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_approvals', 'comments')) {
                $table->text('comments')->nullable()->after('status');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_approvals', 'action_date')) {
                $table->dateTime('action_date')->nullable()->after('comments');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_approvals', 'assigned_by')) {
                $table->unsignedBigInteger('assigned_by')->nullable()->after('action_date');
                $table->foreign('assigned_by')->references('id')->on('staff')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loan_approvals', 'assigned_date')) {
                $table->dateTime('assigned_date')->nullable()->after('assigned_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_approvals')) {
            return;
        }

        Schema::connection('tenant')->table('loan_approvals', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_approvals', 'assigned_date')) {
                $table->dropColumn('assigned_date');
            }
            if (Schema::connection('tenant')->hasColumn('loan_approvals', 'assigned_by')) {
                $table->dropForeign(['assigned_by']);
                $table->dropColumn('assigned_by');
            }
            if (Schema::connection('tenant')->hasColumn('loan_approvals', 'action_date')) {
                $table->dropColumn('action_date');
            }
            if (Schema::connection('tenant')->hasColumn('loan_approvals', 'comments')) {
                $table->dropColumn('comments');
            }
            if (Schema::connection('tenant')->hasColumn('loan_approvals', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::connection('tenant')->hasColumn('loan_approvals', 'approval_level')) {
                $table->dropColumn('approval_level');
            }
            if (Schema::connection('tenant')->hasColumn('loan_approvals', 'approver_id')) {
                $table->dropForeign(['approver_id']);
                $table->dropColumn('approver_id');
            }
            if (Schema::connection('tenant')->hasColumn('loan_approvals', 'approval_id')) {
                $table->dropColumn('approval_id');
            }
        });
    }
};
