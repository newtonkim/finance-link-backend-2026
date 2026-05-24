<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    protected array $tables = [
        'staff',
        'members',
        'member_charges',
        'transaction_reversals',
        'transactions',
        'savings_accounts',
        'savings_group_members',
        'savings_groups',
        'savings_product_charges',
        'savings_products',
        'savings_transfer',
        'shares',
        'system_settings',
        'loans',
        'loan_schedules',
        'general_charges',
        'sacco_branding',
        'savings_account_transfers',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::connection($this->connection)->hasTable($table)) {
                continue;
            }

            if (Schema::connection($this->connection)->hasColumn($table, 'branch_id')) {
                continue;
            }

            $this->fixInvalidDatetimes($table);

            Schema::connection($this->connection)->table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('branch_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('branches')
                    ->nullOnDelete();
            });
        }

        $headOfficeId = $this->ensureHeadOfficeBranch();

        foreach ($this->tables as $table) {
            if (
                ! Schema::connection($this->connection)->hasTable($table)
                || ! Schema::connection($this->connection)->hasColumn($table, 'branch_id')
            ) {
                continue;
            }

            DB::connection($this->connection)
                ->table($table)
                ->whereNull('branch_id')
                ->update(['branch_id' => $headOfficeId]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (
                ! Schema::connection($this->connection)->hasTable($table)
                || ! Schema::connection($this->connection)->hasColumn($table, 'branch_id')
            ) {
                continue;
            }

            Schema::connection($this->connection)->table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('branch_id');
            });
        }
    }

    protected function fixInvalidDatetimes(string $table): void
    {
        $now = now()->toDateTimeString();
        $columns = ['created_at', 'updated_at'];

        foreach ($columns as $column) {
            if (! Schema::connection($this->connection)->hasColumn($table, $column)) {
                continue;
            }

            DB::connection($this->connection)
                ->table($table)
                ->whereRaw("CAST(`{$column}` AS CHAR) = '0000-00-00 00:00:00'")
                ->update([$column => $now]);
        }
    }

    protected function ensureHeadOfficeBranch(): int
    {
        $branch = DB::connection($this->connection)
            ->table('branches')
            ->where('name', 'Head Office')
            ->first();

        if ($branch) {
            return (int) $branch->id;
        }

        $payload = [
            'name' => 'Head Office',
        ];

        $optionalColumns = [
            'code' => null,
            'phone' => null,
            'email' => null,
            'address' => null,
            'manager_name' => null,
            'is_active' => true,
            'system_type' => 'system',
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ];

        foreach ($optionalColumns as $column => $value) {
            if (Schema::connection($this->connection)->hasColumn('branches', $column)) {
                $payload[$column] = $value;
            }
        }

        return (int) DB::connection($this->connection)
            ->table('branches')
            ->insertGetId($payload);
    }
};
