<?php

namespace Tests\Feature\Central;

use App\Central\Models\Plan;
use App\Models\Staff;
use App\Domain\Tenancy\Entities\Tenant;
use App\Models\PlatformUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Concerns\RefreshTenantDatabase;
use App\Central\Services\TenantProvisioningService;

class TenantAdminCreationTest extends TestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.central_domain' => 'central.mfukopro.test',
            'app.central_domains' => ['central.mfukopro.test'],
        ]);
    }

    /** @test */
    public function test_tenant_creation_provisions_admin_user_correctly()
    {
        // 1. Setup Platform Admin and Plan
        // Use a unique email to avoid duplicate key issues across test runs without transactions on master
        $email = 'admin-' . uniqid() . '@mfukopro.test';
        $platformAdmin = PlatformUser::create([
            'email' => $email,
            'name' => 'Platform Admin',
            'password' => Hash::make('password'),
        ]);

        $plan = Plan::firstOrCreate(
            ['slug' => 'gold'],
            [
                'name' => 'Gold Plan',
                'price' => 5000,
                'billing_cycle' => 'yearly',
                'days' => 365,
            ]
        );

        $subdomain = 'maziwalala' . uniqid();
        $adminEmail = 'admin-' . uniqid() . '@maziwalala.com';
        $tenantData = [
            'name' => 'Maziwalala Sacco',
            'subdomain' => $subdomain,
            'plan' => $plan->id,
            'license_months' => 'yearly',
            'admin_name' => 'Sacco Admin',
            'admin_email' => $adminEmail,
            'admin_password' => 'secret123',
        ];

        // 2. Mock infrastructure parts to keep the test fast and within the test DB
        // Use partialMock so createTenantRecord still works
        $tenantServiceMock = $this->partialMock(\App\Domain\Tenancy\Services\TenantService::class);
        $tenantServiceMock->shouldReceive('createPhysicalDatabase')->once();
        $tenantServiceMock->shouldReceive('switchToTenant')->once();
        $tenantServiceMock->shouldReceive('runMigrations')->once();

        // 3. Create the tenant via API
        $response = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->actingAs($platformAdmin, 'platform')
            ->postJson('/api/v1/central/tenants/create', $tenantData);

        $response->assertStatus(201);
        
        // 4. In Laravel 11, the controller uses defer().
        // To trigger deferred tasks in a test, we call terminate().
        app()->terminate();

        // 5. Verify Admin creation in Tenant Database
        // In the test bootstrap, 'tenant' connection points to the test DB.
        $this->assertDatabaseHas('staff', [
            'email' => $adminEmail,
            'role' => 'Admin',
            'is_tenant_admin' => true,
        ], 'tenant');

        // 5. Verify credentials
        $staff = \App\Models\Staff::on('tenant')->where('email', $adminEmail)->first();
        $this->assertNotNull($staff, 'Staff member was not created by the deferred task.');
        $this->assertTrue(Hash::check('secret123', $staff->password));
    }
}
