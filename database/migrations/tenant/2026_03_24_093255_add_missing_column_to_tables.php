<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private $tables = [
        'branches',
        'chart_of_accounts',
        'financial_years',
        'general_charges',
        'general_ledger',
        'journal_entries',
        'journal_entry_lines',
        'loans',
        'loan_schedules',
        'members',
        'member_charges',
        'onboarding_settings',
        'permissions',
        'permissions_users',
        'roles',
        'sacco_branding',
        'savings_accounts',
        'savings_account_transfers',
        'savings_groups',
        'savings_group_members',
        'savings_products',
        'savings_product_charges',
        'savings_transfer',
        'shares',
        'staff',
        'staff_branch_access',
        'sub_ledger',
        'system_settings',
        'transactions',
        'transaction_reversals',
    ];

    public function up(): void
    {

        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                if (! Schema::hasColumn($tableName, 'code')) {
                    Schema::table($tableName, function (Blueprint $table) {
                        $table->string('code')->nullable()->after('id')->index();
                    });
                }
                if (! Schema::hasColumn($tableName, 'branch_id')) {
                    Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                        if ($tableName != 'branches') {
                            $table->bigInteger('branch_id')->nullable()->index()->after('id');
                        }
                    });
                }

                if (! Schema::hasColumn($tableName, 'created_by')) {
                    Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                        $col = $table->bigInteger('created_by')->nullable()->index();
                        if (Schema::hasColumn($tableName, 'created_at')) {
                            $col->after('created_at');
                        }
                    });
                }
                if (! Schema::hasColumn($tableName, 'deleted_at')) {
                    Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                        $col = $table->timestamp('deleted_at')->nullable()->index();
                        if (Schema::hasColumn($tableName, 'created_at')) {
                            $col->after('created_at');
                        }
                    });
                }
                if (! Schema::hasColumn($tableName, 'system_type')) {
                    Schema::table($tableName, function (Blueprint $table) {
                        $table->enum('system_type', ['system', 'user_created'])->nullable()->default('user_created');
                    });
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'code')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('code');
                    $table->dropColumn('branch_id');
                });
            }
        }
    }
};
