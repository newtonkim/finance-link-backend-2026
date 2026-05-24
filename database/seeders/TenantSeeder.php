<?php

namespace Database\Seeders;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantSeeder extends Seeder
{
    /**
     * Seed the tenant database with essential data.
     */
    public function run(): void
    {
        $this->seedChartOfAccounts();
        $this->seedSavingsProducts();

        $this->call([
            SaccoSettingsSeeder::class,
            PermissionSeederTenants::class,
            TenantRoleSeeder::class,        // roles (with branch_scope) before permissions
            PublicHolidaySeeder::class,
            SaccoRolesSeeder::class,
            ExpenseCategorySeeder::class,
            LoanProductSeeder::class,
        ]);

        $this->backfillStaffBranchId();
    }

    /**
     * Backfill branch_id for any staff (e.g. initial admin) created before a branch was assigned.
     */
    private function backfillStaffBranchId(): void
    {
        $defaultBranchId = DB::connection('tenant')
            ->table('branches')
            ->orderBy('id')
            ->value('id');

        if (! $defaultBranchId) {
            return;
        }

        DB::connection('tenant')
            ->table('staff')
            ->whereNull('branch_id')
            ->update(['branch_id' => $defaultBranchId]);
    }

    /**
     * Seed the default Chart of Accounts for the SACCO.
     *
     * Earlier this issued ~3 queries per template row (parent lookup in master + parent
     * lookup in tenant + updateOrCreate). Template rows are processed in level order, so
     * parents are always inserted before children — we can resolve parent IDs from an
     * in-memory map instead of round-tripping the DB.
     */
    private function seedChartOfAccounts(): void
    {
        $template = DB::connection('master')
            ->table('coa_templates')
            ->where('template_type', 'SACCO_UGANDA')
            ->first();

        if (! $template) {
            Log::warning('SACCO_UGANDA COA template not found in master database.');

            return;
        }

        $accounts = DB::connection('master')
            ->table('coa_template_accounts')
            ->where('template_id', $template->id)
            ->orderBy('level')
            ->get();

        // template_id => gl_code, so parent_template_id can be resolved to a gl_code without a DB query.
        $glByTemplateId = $accounts->pluck('gl_code', 'id')->all();

        // gl_code => tenant ChartOfAccount id, populated as we insert so children find their parent in memory.
        $tenantIdByGl = [];

        foreach ($accounts as $account) {
            $parentId = null;
            if ($account->parent_template_id && isset($glByTemplateId[$account->parent_template_id])) {
                $parentId = $tenantIdByGl[$glByTemplateId[$account->parent_template_id]] ?? null;
            }

            $tenantCoa = ChartOfAccount::updateOrCreate(
                ['gl_code' => $account->gl_code],
                [
                    'name' => $account->name,
                    'account_subtype' => $account->account_subtype,
                    'account_type' => $account->account_type,
                    'normal_balance' => $account->normal_balance,
                    'level' => $account->level,
                    'parent_id' => $parentId,
                    'is_control' => $account->is_control,
                    'is_postable' => $account->is_postable,
                    'allow_manual' => $account->allow_manual,
                    'ifrs_category' => $account->ifrs_category,
                    'is_active' => true,
                ]
            );

            $tenantIdByGl[$account->gl_code] = $tenantCoa->id;
        }
    }

    /**
     * Seed default savings products.
     */
    private function seedSavingsProducts(): void
    {
        SavingsProduct::updateOrCreate(
            ['name' => 'General Savings Account'],
            [
                'code' => 'GSA-001',
                'type' => 'standard',
                'minimum_balance' => 0,
                'status' => 'active',
            ]
        );
    }
}
