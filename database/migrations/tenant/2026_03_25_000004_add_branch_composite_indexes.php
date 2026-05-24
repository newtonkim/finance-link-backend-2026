<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes for branch-scoped queries on high-traffic tables.
 *
 * Every list endpoint filters by branch_id and orders/filters by a second
 * column. Adding a covering (branch_id, <col>) index avoids full-table scans
 * once data grows across multiple branches.
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $this->addIndex('members', 'branch_id', 'status', 'members_branch_status_idx');
        $this->addIndex('members', 'branch_id', 'created_at', 'members_branch_created_idx');
        $this->addIndex('transactions', 'branch_id', 'type', 'transactions_branch_type_idx');
        $this->addIndex('transactions', 'branch_id', 'created_at', 'transactions_branch_created_idx');
        $this->addIndex('savings_accounts', 'branch_id', 'status', 'savings_accounts_branch_status_idx');
        $this->addIndex('savings_accounts', 'branch_id', 'member_id', 'savings_accounts_branch_member_idx');
        $this->addIndex('loans', 'branch_id', 'status', 'loans_branch_status_idx');
        $this->addIndex('loans', 'branch_id', 'member_id', 'loans_branch_member_idx');
        $this->addIndex('journal_entries', 'branch_id', 'created_at', 'journal_entries_branch_created_idx');
        $this->addIndex('journal_entry_lines', 'branch_id', 'created_at', 'journal_entry_lines_branch_created_idx');
        $this->addIndex('staff', 'branch_id', 'status', 'staff_branch_status_idx');
    }

    public function down(): void
    {
        $this->dropIndex('members', 'members_branch_status_idx');
        $this->dropIndex('members', 'members_branch_created_idx');
        $this->dropIndex('transactions', 'transactions_branch_type_idx');
        $this->dropIndex('transactions', 'transactions_branch_created_idx');
        $this->dropIndex('savings_accounts', 'savings_accounts_branch_status_idx');
        $this->dropIndex('savings_accounts', 'savings_accounts_branch_member_idx');
        $this->dropIndex('loans', 'loans_branch_status_idx');
        $this->dropIndex('loans', 'loans_branch_member_idx');
        $this->dropIndex('journal_entries', 'journal_entries_branch_created_idx');
        $this->dropIndex('journal_entry_lines', 'journal_entry_lines_branch_created_idx');
        $this->dropIndex('staff', 'staff_branch_status_idx');
    }

    private function addIndex(string $table, string $col1, string $col2, string $name): void
    {
        if (! Schema::connection($this->connection)->hasTable($table)) {
            return;
        }

        if (! Schema::connection($this->connection)->hasColumn($table, $col1)
            || ! Schema::connection($this->connection)->hasColumn($table, $col2)) {
            return;
        }

        if ($this->hasIndex($table, $name)) {
            return;
        }

        Schema::connection($this->connection)->table($table, function (Blueprint $blueprint) use ($col1, $col2, $name) {
            $blueprint->index([$col1, $col2], $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::connection($this->connection)->hasTable($table)) {
            return;
        }

        if (! $this->hasIndex($table, $name)) {
            return;
        }

        Schema::connection($this->connection)->table($table, function (Blueprint $blueprint) use ($name) {
            $blueprint->dropIndex($name);
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        return Schema::connection($this->connection)->hasIndex($table, $indexName);
    }
};
