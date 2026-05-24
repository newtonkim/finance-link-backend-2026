<?php

namespace Tests\Feature\Central;

use Tests\TestCase;

class TenantProvisioningTest extends TestCase
{
    public function test_provisioning_flow_without_tenant_transaction()
    {
        // 1. Mock TenantService to avoid slow physical DB operations
        $tenantServiceMock = $this->mock(\App\Domain\Tenancy\Services\TenantService::class);
        $licenseServiceMock = $this->mock(\App\Central\Services\LicenseService::class);
        
        /** @var \App\Central\Services\TenantProvisioningService $service */
        $service = app(\App\Central\Services\TenantProvisioningService::class);

        // 2. Setup prerequisites
        $plan = \App\Central\Models\Plan::create([
            'name' => 'Basic Plan',
            'slug' => 'basic',
            'price' => 10.00,
            'billing_cycle' => 'monthly',
            'days' => 30,
        ]);

        $data = [
            'name' => 'Wazalendo Sacco',
            'subdomain' => 'wazalendo',
            'plan' => $plan->id,
            'license_months' => 'monthly',
            'admin_name' => 'John Doe',
            'admin_email' => 'admin@wazalendo.com',
            'admin_password' => 'password123',
        ];

        // 3. Define expectations for Phase 1 (indirectly via createBaseAccount)
        $expectedTenant = new \App\Domain\Tenancy\Entities\Tenant([
            'id' => \Illuminate\Support\Str::uuid(),
            'name' => $data['name'],
            'subdomain' => $data['subdomain'],
            'database_name' => 'sacco_wazalendo',
            'status' => 'active',
        ]);
        
        $tenantServiceMock->shouldReceive('createTenantRecord')
            ->once()
            ->with($data['name'], $data['subdomain'])
            ->andReturn($expectedTenant);

        $licenseServiceMock->shouldReceive('assignLicense')
            ->once()
            ->with($expectedTenant, $plan->id, \Mockery::any());

        // 4. Define expectations for Phase 2
        $tenantServiceMock->shouldReceive('createPhysicalDatabase')->once()->with($expectedTenant);
        $tenantServiceMock->shouldReceive('switchToTenant')->once()->with($expectedTenant);
        $tenantServiceMock->shouldReceive('runMigrations')->once()->with($expectedTenant);

        // 5. Run Phase 1
        $tenant = $service->createBaseAccount($data);
        $this->assertEquals($data['subdomain'], $tenant->subdomain);

        // 6. Run Phase 2
        // We need to ensure the admin staff creation doesn't fail due to missing tables in the mock context
        // Actually, since we are using DatabaseTransactions on the shared test DB, 
        // the tables ALREADY exist. So it should work!
        $service->provisionResources($tenant, $data);

        // 7. Verify admin was created
        $this->assertDatabaseHas('staff', [
            'email' => 'admin@wazalendo.com',
            'is_tenant_admin' => true,
        ], 'tenant');
    }
}
