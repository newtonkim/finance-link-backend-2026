<?php

namespace Tests\Feature\Central;

use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use App\Models\PlatformUser;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class CentralRegistrationTest extends TestCase
{
    use RefreshTenantDatabase;
    
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.central_domain' => 'central.mfukopro.test',
            'app.central_domains' => ['central.mfukopro.test'],
        ]);

        // Setup initial plan for tests
        Plan::firstOrCreate(
            ['slug' => 'test-plan'],
            [
                'name' => 'Test Plan',
                'price' => 10.00,
                'features' => json_encode(['users' => 10]),
            ]
        );

    }

    /** @test */
    public function test_platform_admin_can_access_dashboard_summary()
    {
        $admin = PlatformUser::create([
            'name' => 'Platform Admin',
            'email' => 'platform-admin@example.com',
            'password' => Hash::make('password'),
        ]);

        $response = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->actingAs($admin, 'platform')
            ->getJson('/api/v1/central/dashboard/summary');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'total_tenants',
            'active_tenants',
            'expired_licenses',
            'expiring_soon_3_days',
            'revenue_metrics',
        ]);
    }

    /** @test */
    public function test_unauthorized_host_cannot_access_central_api()
    {
        $admin = PlatformUser::create([
            'name' => 'Platform Admin',
            'email' => 'platform-admin-2@example.com',
            'password' => Hash::make('password'),
        ]);

        // Use the test DB as the tenant DB so SetTenantDatabase doesn't fail
        // before EnsureCentralDomain gets a chance to reject the request.
        Tenant::factory()->create([
            'subdomain' => 'tenant1',
            'database_name' => config('database.connections.mysql.database', 'mfukopro_test'),
        ]);

        $response = $this->withHeaders(['X-Forwarded-Host' => 'tenant1.mfukopro.test'])
            ->actingAs($admin, 'platform')
            ->getJson('/api/v1/central/dashboard/summary');

        $response->assertStatus(403);
    }
}
