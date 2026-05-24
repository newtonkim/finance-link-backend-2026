<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('roles')) {
            return;
        }

        if ($this->hasColumn('roles', 'branch_scope')) {
            return;
        }

        Schema::connection('tenant')->table('roles', function (Blueprint $table) {
            // 'all' = tenant admin sees everything
            // 'branch' = sees own branch only
            // 'self' = sees only own records (most restricted, safe default)
            $table->string('branch_scope')->default('self')->after('description')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('roles')) {
            return;
        }

        if (! $this->hasColumn('roles', 'branch_scope')) {
            return;
        }

        Schema::connection('tenant')->table('roles', function (Blueprint $table) {
            $table->dropColumn('branch_scope');
        });
    }

    private function hasColumn(string $table, string $column): bool
    {
        return DB::connection('tenant')->table('information_schema.columns')
            ->where('table_schema', DB::connection('tenant')->getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }
};
