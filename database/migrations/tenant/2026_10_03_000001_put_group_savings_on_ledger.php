<?php

use App\Tenant\Modules\Accounting\GlCodes;
use App\Tenant\Modules\Accounting\Services\GroupSavingsJournalService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Group savings in the general ledger, and group guarantors in recovery.
 *
 * chart_of_accounts: 21104 Group Savings Deposits, under Member Savings Liability.
 * group_savings_accounts.gl_opened_at: when the account's balance was brought onto
 *   the ledger; only accounts with it set are journaled.
 * guarantor_recovery_lines: a line can now take money from a group savings account
 *   rather than a member's own, so member_id and savings_account_id become optional;
 *   group_member_split records how it was taken from the group's members.
 *
 * Existing group accounts are then brought onto the ledger with an opening balance
 * against Opening Balance Control. Any that cannot be (no chart of accounts yet)
 * can be brought on later with `php artisan group-savings:open-ledger`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('tenant');
        $db = DB::connection('tenant');

        if ($schema->hasTable('chart_of_accounts') && ! $db->table('chart_of_accounts')->where('gl_code', GlCodes::SAVINGS_GROUP)->exists()) {
            $parent = $db->table('chart_of_accounts')->where('gl_code', '21100')->first();

            // A tenant with no chart of accounts gets 21104 with the rest of it.
            if ($parent) {
                $db->table('chart_of_accounts')->insert([
                    'gl_code' => GlCodes::SAVINGS_GROUP,
                    'name' => 'Group Savings Deposits',
                    'account_type' => 'LIABILITY',
                    'account_subtype' => 'Member Deposit',
                    'normal_balance' => 'CR',
                    'level' => 4,
                    'parent_id' => $parent->id,
                    'is_control' => false,
                    'is_postable' => true,
                    'allow_manual' => false,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if ($schema->hasTable('group_savings_accounts') && ! $schema->hasColumn('group_savings_accounts', 'gl_opened_at')) {
            $schema->table('group_savings_accounts', function (Blueprint $table) {
                $table->timestamp('gl_opened_at')->nullable()->after('balance');
            });
        }

        if ($schema->hasTable('guarantor_recovery_lines') && ! $schema->hasColumn('guarantor_recovery_lines', 'group_savings_account_id')) {
            $schema->table('guarantor_recovery_lines', function (Blueprint $table) {
                $table->unsignedBigInteger('member_id')->nullable()->change();
                $table->unsignedBigInteger('savings_account_id')->nullable()->change();
                $table->unsignedBigInteger('group_savings_account_id')->nullable()->index()->after('savings_account_id');
                $table->json('group_member_split')->nullable()->after('group_savings_account_id')
                    ->comment('savings_group_members.id => amount taken from that member');
            });
        }

        $this->openExistingLedgers();
    }

    public function down(): void
    {
        $schema = Schema::connection('tenant');

        if ($schema->hasColumn('guarantor_recovery_lines', 'group_savings_account_id')) {
            $schema->table('guarantor_recovery_lines', function (Blueprint $table) {
                $table->dropIndex(['group_savings_account_id']);
                $table->dropColumn(['group_savings_account_id', 'group_member_split']);
            });
        }

        if ($schema->hasColumn('group_savings_accounts', 'gl_opened_at')) {
            $schema->table('group_savings_accounts', function (Blueprint $table) {
                $table->dropColumn('gl_opened_at');
            });
        }
    }

    private function openExistingLedgers(): void
    {
        $db = DB::connection('tenant');
        $needed = [GlCodes::SAVINGS_GROUP, GlCodes::OPENING_BALANCE_CONTROL];

        if (! Schema::connection('tenant')->hasTable('journal_entries')
            || $db->table('chart_of_accounts')->whereIn('gl_code', $needed)->where('is_active', true)->count() < count($needed)) {
            return;
        }

        $result = app(GroupSavingsJournalService::class)->openAllLedgers();

        foreach ($result['failed'] as $failure) {
            Log::warning('Could not bring group savings account onto the ledger', $failure);
        }
    }
};
