<?php

namespace Database\Seeders;

use App\Tenant\Modules\Savings\Models\SavingsProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TenantSeeder extends Seeder
{
    /**
     * Seed the tenant database with essential data.
     */
    public function run(): void
    {
        $this->call(TenantChartOfAccountsSeeder::class);
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
