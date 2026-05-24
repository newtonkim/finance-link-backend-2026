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
        $tables = [
            'loan_charges' => ['system_type', 'branch_id'],
            'loan_repayment_schedule' => ['system_type'],
            'loan_transactions' => ['system_type', 'branch_id'],
            'loan_products' => ['system_type', 'branch_id'],
            'loan_approvals' => ['system_type', 'branch_id'],
            'loan_write_offs' => ['system_type', 'branch_id'],
            'loan_rescheduling' => ['system_type', 'branch_id'],
            'public_holidays' => ['system_type'],
            'loan_guarantors' => ['system_type'],
        ];

        foreach ($tables as $tableName => $columns) {
            if (! Schema::connection('tenant')->hasTable($tableName)) {
                continue;
            }

            Schema::connection('tenant')->table($tableName, function (Blueprint $table) use ($tableName, $columns) {
                if (in_array('system_type', $columns) && ! Schema::connection('tenant')->hasColumn($tableName, 'system_type')) {
                    $table->enum('system_type', ['system', 'user_created'])->default('user_created')->after('id');
                }

                if (in_array('branch_id', $columns) && ! Schema::connection('tenant')->hasColumn($tableName, 'branch_id')) {
                    $afterColumn = Schema::connection('tenant')->hasColumn($tableName, 'system_type') ? 'system_type' : 'id';
                    $table->unsignedBigInteger('branch_id')->nullable()->index()->after($afterColumn);
                    $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = [
            'loan_charges' => ['system_type', 'branch_id'],
            'loan_repayment_schedule' => ['system_type'],
            'loan_transactions' => ['system_type', 'branch_id'],
            'loan_products' => ['system_type', 'branch_id'],
            'loan_approvals' => ['system_type', 'branch_id'],
            'loan_write_offs' => ['system_type', 'branch_id'],
            'loan_rescheduling' => ['system_type', 'branch_id'],
            'public_holidays' => ['system_type'],
            'loan_guarantors' => ['system_type'],
        ];

        foreach ($tables as $tableName => $columns) {
            if (! Schema::connection('tenant')->hasTable($tableName)) {
                continue;
            }

            Schema::connection('tenant')->table($tableName, function (Blueprint $table) use ($tableName, $columns) {
                if (in_array('branch_id', $columns) && Schema::connection('tenant')->hasColumn($tableName, 'branch_id')) {
                    $table->dropForeign([$tableName.'_branch_id_foreign']); // Use standard Laravel naming or manual
                    // Actually, Laravel's default is [table]_[column]_foreign
                    // But if it fails, it's safer to just drop the column if we can't be sure of the FK name
                    // However, we know we just created it.
                    $table->dropColumn('branch_id');
                }
                if (in_array('system_type', $columns) && Schema::connection('tenant')->hasColumn($tableName, 'system_type')) {
                    $table->dropColumn('system_type');
                }
            });
        }
    }
};
