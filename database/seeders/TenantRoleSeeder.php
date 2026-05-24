<?php

namespace Database\Seeders;

use App\Support\BranchContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the default tenant roles with branch_scope values.
 *
 * This is intentionally idempotent — it uses insertOrIgnore so re-running
 * the seeder on an existing tenant is safe. The branch_scope value drives
 * BranchContext::scopeFor() and therefore all read-isolation behaviour.
 *
 * Scopes:
 *   SCOPE_ALL    ('all')    — sees and acts across all branches (tenant admin)
 *   SCOPE_BRANCH ('branch') — sees own branch; can also be granted extra branches via staff_branch_access
 *   SCOPE_SELF   ('self')   — most restricted; safe default
 */
class TenantRoleSeeder extends Seeder
{
    /** @var array<array{name:string, branch_scope:string, description:string}> */
    protected array $roles = [
        [
            'name' => 'tenant_admin',
            'branch_scope' => BranchContext::SCOPE_ALL,
            'description' => 'Full access across all branches. Can create staff, manage settings, and view all data.',
        ],
        [
            'name' => 'branch_manager',
            'branch_scope' => BranchContext::SCOPE_BRANCH,
            'description' => 'Manages a single branch. Can view all members, transactions, and reports within their branch.',
        ],
        [
            'name' => 'accountant',
            'branch_scope' => BranchContext::SCOPE_BRANCH,
            'description' => 'Handles financial records and journal entries within their branch.',
        ],
        [
            'name' => 'teller',
            'branch_scope' => BranchContext::SCOPE_SELF,
            'description' => 'Front-desk staff. Processes deposits, withdrawals, and member onboarding.',
        ],
        [
            'name' => 'loan_officer',
            'branch_scope' => BranchContext::SCOPE_SELF,
            'description' => 'Processes loan applications and repayments within their branch.',
        ],
        [
            'name' => 'auditor',
            'branch_scope' => BranchContext::SCOPE_ALL,
            'description' => 'Read-only access across all branches for compliance and audit purposes.',
        ],
    ];

    public function run(): void
    {
        $hasBranchScope = DB::connection('tenant')
            ->getSchemaBuilder()
            ->hasColumn('roles', 'branch_scope');

        $rows = array_map(function (array $role) use ($hasBranchScope) {
            $row = [
                'name' => $role['name'],
                'description' => $role['description'],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($hasBranchScope) {
                $row['branch_scope'] = $role['branch_scope'];
            }

            return $row;
        }, $this->roles);

        DB::connection('tenant')->table('roles')->insertOrIgnore($rows);

        // If branch_scope column was added after initial seeding, backfill it.
        if ($hasBranchScope) {
            foreach ($this->roles as $role) {
                DB::connection('tenant')
                    ->table('roles')
                    ->where('name', $role['name'])
                    ->whereNull('branch_scope')
                    ->update(['branch_scope' => $role['branch_scope']]);
            }
        }

        // Backfill branch_id with the default (Head Office) branch for any roles that have none.
        $defaultBranchId = DB::connection('tenant')
            ->table('branches')
            ->orderBy('id')
            ->value('id');

        if ($defaultBranchId) {
            DB::connection('tenant')
                ->table('roles')
                ->whereNull('branch_id')
                ->update(['branch_id' => $defaultBranchId]);
        }
    }
}
