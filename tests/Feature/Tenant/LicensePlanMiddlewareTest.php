<?php

namespace Tests\Feature\Tenant;

use App\Central\Models\License;
use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use App\Tenant\Http\Middleware\EnsureLicenseActive;
use App\Tenant\Http\Middleware\EnsurePlanFeatureEnabled;
use Illuminate\Http\Request;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class LicensePlanMiddlewareTest extends TestCase
{
    use RefreshTenantDatabase;

    public function test_expired_license_allows_read_only_requests(): void
    {
        [$tenant] = $this->licensedTenantFixture(['loans' => true], 'expired');

        app()->instance('currentTenant', $tenant);

        $middleware = new EnsureLicenseActive;
        $read = $middleware->handle(Request::create('/api/v1/tenant/loan-products', 'GET'), fn () => response()->json(['ok' => true]));
        $write = $middleware->handle(Request::create('/api/v1/tenant/loan-products', 'POST'), fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $read->getStatusCode());
        $this->assertSame(403, $write->getStatusCode());
    }

    public function test_plan_feature_middleware_blocks_disabled_feature(): void
    {
        [$tenant] = $this->licensedTenantFixture(['loans' => false, 'reports' => true]);

        app()->instance('currentTenant', $tenant);

        $middleware = new EnsurePlanFeatureEnabled;
        $blocked = $middleware->handle(Request::create('/api/v1/tenant/loan-products', 'GET'), fn () => response()->json(['ok' => true]), 'loans');
        $allowed = $middleware->handle(Request::create('/api/v1/tenant/reports', 'GET'), fn () => response()->json(['ok' => true]), 'reports');

        $this->assertSame(403, $blocked->getStatusCode());
        $this->assertSame('loans', $blocked->getData(true)['feature']);
        $this->assertSame(200, $allowed->getStatusCode());
    }

    private function licensedTenantFixture(array $features, string $licenseStatus = 'active'): array
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::create([
            'name' => 'Feature Plan '.uniqid(),
            'slug' => 'feature-plan-'.uniqid(),
            'price' => 1000,
            'billing_cycle' => 'monthly',
            'features' => $features,
        ]);

        License::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'plan' => (string) $plan->id,
            'starts_at' => now()->subMonth()->toDateString(),
            'expires_at' => $licenseStatus === 'expired'
                ? now()->subDay()->toDateString()
                : now()->addMonth()->toDateString(),
            'status' => $licenseStatus,
        ]);

        return [$tenant, $plan];
    }
}
