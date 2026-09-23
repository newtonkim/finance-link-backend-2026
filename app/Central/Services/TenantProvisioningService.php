<?php

namespace App\Central\Services;

use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use App\Domain\Tenancy\Services\TenantService;
use App\Models\Staff;
use Carbon\Carbon;
use Database\Seeders\TenantSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TenantProvisioningService
{
    public function __construct(
        protected TenantService $tenantService,
        protected LicenseService $licenseService
    ) {}

    /**
     * Phase 1: Create the base Tenant and License records (Fast).
     */
    public function createBaseAccount(array $data): Tenant
    {
        return DB::connection('master')->transaction(function () use ($data) {
            $tenant = $this->tenantService->createTenantRecord($data['name'], $data['subdomain']);
            $plan = Plan::where('id', $data['plan'])->firstOrFail();

            // license_months is an integer (e.g. 3, 6, 12, 24); convert to days.
            $months = (int) $data['license_months'];
            $days = $months > 0
                ? (int) Carbon::now()->addMonths($months)->diffInDays(Carbon::now())
                : ($plan->days ?? 30);

            $this->licenseService->assignLicense($tenant, $plan->id, $days);

            return $tenant;
        });
    }

    /**
     * Phase 2: Provision the heavy resources (Slow).
     *
     * @throws Throwable
     */
    public function provisionResources(Tenant $tenant, array $data): void
    {
        try {
            // 2. Create Physical Database
            $this->tenantService->createPhysicalDatabase($tenant);

            // 3. Switch to Tenant DB context
            $this->tenantService->switchToTenant($tenant);

            // 4. Run tenant setup operations
            // a. Run Tenant Migrations
            $this->tenantService->runMigrations($tenant);

            // b. Run Seeders first to ensure roles, permissions and settings exist
            $seedExitCode = Artisan::call('db:seed', [
                '--database' => 'tenant',
                '--class' => TenantSeeder::class,
                '--force' => true,
            ]);

            if ($seedExitCode !== 0) {
                throw new \RuntimeException('Tenant seeding failed: '.Artisan::output());
            }

            // c. Create initial admin staff — assign to the default (Head Office) branch
            $defaultBranchId = $this->ensureDefaultBranch();

            Log::info("Creating initial admin for tenant {$tenant->subdomain} at branch {$defaultBranchId}");

            // Explicitly use the tenant connection for staff creation
            $admin = Staff::on('tenant')->updateOrCreate(
                ['email' => $data['admin_email']],
                [
                    'name' => $data['admin_name'] ?? 'Administrator',
                    'password' => $data['admin_password'], // Staff model handles hashing via casts
                    'role' => 'Admin',
                    'is_tenant_admin' => true,
                    'branch_id' => $defaultBranchId,
                    'status' => 'active',
                ]
            );

            if ($admin) {
                Log::info("Admin created successfully for {$tenant->subdomain}: ID {$admin->id}, Email {$admin->email}");
            } else {
                Log::error("Staff::create returned null for tenant {$tenant->subdomain}");
            }
        } catch (Throwable $e) {
            Log::error("Failed to provision tenant resources for {$tenant->subdomain}: ".$e->getMessage());
            throw $e;
        }
    }

    protected function ensureDefaultBranch(): int
    {
        if (! Schema::connection('tenant')->hasTable('branches')) {
            throw new \Exception('Branches table does not exist in tenant database.');
        }

        $branchId = DB::connection('tenant')->table('branches')
            ->where('name', 'Head Office')
            ->value('id');

        if ($branchId) {
            return (int) $branchId;
        }

        return DB::connection('tenant')->table('branches')->insertGetId([
            'name' => 'Head Office',
            'code' => 'HQ-001',
            'system_type' => 'system',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
