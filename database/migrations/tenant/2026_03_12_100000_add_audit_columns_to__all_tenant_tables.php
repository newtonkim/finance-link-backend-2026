<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * All tenant business tables that should carry audit columns.
     */
    protected array $tables = [
        'staff',
        'members',
        'savings_accounts',
        'savings_products',
        'savings_product_charges',
        'savings_groups',
        'savings_group_members',
        'shares',
        'loans',
        'loan_schedules',
        'transactions',
        'chart_of_accounts',
        'journal_entries',
        'journal_entry_lines',
        'general_ledger',
        'sub_ledger',
        'onboarding_settings',
        'financial_years',
        'permissions_users',
        'permissions',
        'roles',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                continue;
            }

            Schema::connection('tenant')->table($table, function (Blueprint $t) use ($table) {
                $auditColumns = [
                    'created_by'  => fn () => $t->unsignedBigInteger('created_by')->nullable(),
                    'updated_by'  => fn () => $t->unsignedBigInteger('updated_by')->nullable(),
                    'deleted_by'  => fn () => $t->unsignedBigInteger('deleted_by')->nullable(),
                    'system_type' => fn () => $t->enum('system_type', ['system', 'user_created'])->nullable()->default('user_created'),
                ];

                foreach ($auditColumns as $column => $addColumn) {
                    if (Schema::connection('tenant')->hasColumn($table, $column)) {
                        continue;
                    }

                    $addColumn();

                    // Only add the index if one with the conventional name doesn't already
                    // exist (e.g. a previous migration renamed the column but left the
                    // old index behind — MySQL preserves index names across renames).
                    $indexName = "{$table}_{$column}_index";
                    if (! $this->indexExists($table, $indexName)) {
                        $t->index($column);
                    }
                }
            });
        }
    }

    /**
     * Check whether an index with the given name already exists on a table.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = Schema::connection('tenant')->getIndexes($table);

        foreach ($indexes as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::connection('tenant')->hasTable($table)) {
                continue;
            }

            Schema::connection('tenant')->table($table, function (Blueprint $t) use ($table) {
                $toDrop = [];

                foreach (['created_by', 'updated_by', 'deleted_by', 'system_type'] as $column) {
                    if (Schema::connection('tenant')->hasColumn($table, $column)) {
                        $toDrop[] = $column;
                    }
                }

                if ($toDrop) {
                    $t->dropColumn($toDrop);
                }
            });
        }
    }
};
