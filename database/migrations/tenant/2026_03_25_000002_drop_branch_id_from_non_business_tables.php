<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop branch_id from tenant-wide configuration / settings tables.
 *
 * These tables represent Sacco-wide configuration that is not scoped to a branch:
 *   - savings_products         product catalogue (applies to all branches)
 *   - savings_product_charges  charge templates on products
 *   - general_charges          global fee definitions
 *   - sacco_branding           single row of branding config
 *   - system_settings          single row of system config
 *
 * Business/activity tables (members, transactions, savings_accounts, loans, …)
 * keep their branch_id and are NOT touched here.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    /** Tables that are Sacco-wide config — branch_id does not belong here. */
    protected array $tables = [
        'savings_products',
        'savings_product_charges',
        'general_charges',
        'sacco_branding',
        'system_settings',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::connection($this->connection)->hasTable($table)) {
                continue;
            }

            if (! Schema::connection($this->connection)->hasColumn($table, 'branch_id')) {
                continue;
            }

            Schema::connection($this->connection)->table($table, function (Blueprint $blueprint) use ($table) {
                $foreignKeyName = "{$table}_branch_id_foreign";

                if ($this->hasForeignKey($table, $foreignKeyName)) {
                    $blueprint->dropForeign($foreignKeyName);
                }

                $blueprint->dropColumn('branch_id');
            });
        }
    }

    public function down(): void
    {
        // Restore branch_id as nullable FK on rollback so the migration is reversible.
        foreach ($this->tables as $table) {
            if (! Schema::connection($this->connection)->hasTable($table)) {
                continue;
            }

            if (Schema::connection($this->connection)->hasColumn($table, 'branch_id')) {
                continue;
            }

            Schema::connection($this->connection)->table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('branch_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('branches')
                    ->nullOnDelete();
            });
        }
    }

    private function hasForeignKey(string $table, string $constraint): bool
    {
        return DB::connection($this->connection)->table('information_schema.table_constraints')
            ->where('constraint_schema', DB::connection($this->connection)->getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
