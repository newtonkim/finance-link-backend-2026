<?php

namespace Tests\Feature\Central;

use App\Central\Models\License;
use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use App\Models\PlatformUser;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class TenantListPlanDetailsTest extends TestCase
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

    public function test_tenant_list_includes_current_plan_details_for_the_drawer(): void
    {
        $admin = PlatformUser::create([
            'name' => 'Tenant Admin',
            'email' => 'tenant-list-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);

        $tenant = Tenant::factory()->create();
        $plan = Plan::create([
            'name' => 'Growth',
            'slug' => 'growth-'.uniqid(),
            'price' => 149.50,
            'billing_cycle' => 'monthly',
            'max_members' => 500,
            'max_users' => 25,
            'features' => ['reports' => true],
        ]);
        $license = License::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'plan' => $plan->name,
            'starts_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
            'status' => 'active',
        ]);

        $response = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->actingAs($admin, 'platform')
            ->postJson('/api/v1/central/tenants/list', ['status' => 'all']);

        $response->assertOk();
        $response->assertJsonPath('payload.data.0.id', $tenant->id);
        $response->assertJsonPath('payload.data.0.license_id', $license->id);
        $response->assertJsonPath('payload.data.0.plan_name', 'Growth');
        $response->assertJsonPath('payload.data.0.plan_slug', $plan->slug);
        $response->assertJsonPath('payload.data.0.cost', '149.50');
        $response->assertJsonPath('payload.data.0.billing_type', 'monthly');
        $response->assertJsonPath('payload.data.0.mx_mbrs', 500);
        $response->assertJsonPath('payload.data.0.mxusrs', 25);
        $response->assertJsonPath('payload.data.0.features.reports', true);
    }
}
