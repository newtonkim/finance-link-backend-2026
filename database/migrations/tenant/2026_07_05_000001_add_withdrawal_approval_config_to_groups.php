<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many designated approvers must sign off a member withdrawal from a
        // group. 0 = withdrawals execute immediately (no approval needed).
        if (Schema::connection('tenant')->hasTable('savings_groups')
            && ! Schema::connection('tenant')->hasColumn('savings_groups', 'withdrawal_required_approvals')) {
            Schema::connection('tenant')->table('savings_groups', function (Blueprint $table) {
                $table->unsignedTinyInteger('withdrawal_required_approvals')->default(1);
            });
        }

        // Flag group members who are allowed to approve/reject withdrawals.
        if (Schema::connection('tenant')->hasTable('savings_group_members')) {
            Schema::connection('tenant')->table('savings_group_members', function (Blueprint $table) {
                if (! Schema::connection('tenant')->hasColumn('savings_group_members', 'is_approver')) {
                    $table->boolean('is_approver')->default(false)->index();
                }
                if (! Schema::connection('tenant')->hasColumn('savings_group_members', 'approver_role')) {
                    $table->string('approver_role', 50)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('savings_groups', 'withdrawal_required_approvals')) {
            Schema::connection('tenant')->table('savings_groups', function (Blueprint $table) {
                $table->dropColumn('withdrawal_required_approvals');
            });
        }
        if (Schema::connection('tenant')->hasTable('savings_group_members')) {
            Schema::connection('tenant')->table('savings_group_members', function (Blueprint $table) {
                foreach (['is_approver', 'approver_role'] as $col) {
                    if (Schema::connection('tenant')->hasColumn('savings_group_members', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
