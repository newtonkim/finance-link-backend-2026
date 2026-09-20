<?php

namespace Tests\Feature\Central;

use App\Central\Models\License;
use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use App\Models\PlatformUser;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * The dashboard is the only place the platform owner sees whether the business is
 * healthy, so every figure it reports has to be defensible. These assertions are
 * written as deltas against a baseline rather than absolutes, so they hold
 * regardless of what other tests or seeds have left in the central database.
 */
class CentralDashboardTest extends TestCase
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

    private function admin(): PlatformUser
    {
        return PlatformUser::create([
            'name' => 'Dashboard Admin',
            'email' => 'dash-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    private function analytics(PlatformUser $admin): array
    {
        $response = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->actingAs($admin, 'platform')
            ->postJson('/api/v1/central/dashboard/analytics');

        $response->assertOk();

        return $response->json('payload');
    }

    private function plan(string $slug, float $price, string $cycle): Plan
    {
        return Plan::firstOrCreate(['slug' => $slug], [
            'name' => ucfirst($slug),
            'price' => $price,
            'billing_cycle' => $cycle,
            'days' => 30,
        ]);
    }

    private function license(Plan $plan, string $expiresAt): License
    {
        return License::create([
            'tenant_id' => Tenant::factory()->create()->id,
            'plan_id' => $plan->id,
            'plan' => (string) $plan->id,
            'starts_at' => now()->subMonths(2)->toDateString(),
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);
    }

    public function test_expired_licenses_do_not_count_towards_mrr(): void
    {
        // The original implementation summed plan price across every license with no
        // expiry filter, so a lapsed tenant still reported as recurring revenue.
        $admin = $this->admin();
        $baseline = (float) $this->analytics($admin)['revenue']['mrr'];

        $this->license($this->plan('dash-expired', 500, 'monthly'), now()->subDay()->toDateString());

        $this->assertSame($baseline, (float) $this->analytics($admin)['revenue']['mrr']);
    }

    public function test_active_monthly_license_adds_its_price_to_mrr(): void
    {
        $admin = $this->admin();
        $baseline = (float) $this->analytics($admin)['revenue']['mrr'];

        $this->license($this->plan('dash-monthly', 500, 'monthly'), now()->addMonth()->toDateString());

        $this->assertSame($baseline + 500.0, (float) $this->analytics($admin)['revenue']['mrr']);
    }

    public function test_yearly_license_is_normalised_to_a_monthly_figure(): void
    {
        $admin = $this->admin();
        $baseline = (float) $this->analytics($admin)['revenue']['mrr'];

        $this->license($this->plan('dash-yearly', 1200, 'yearly'), now()->addMonths(6)->toDateString());

        // 1200/yr is 100/mo of recurring revenue, not 1200.
        $this->assertSame($baseline + 100.0, (float) $this->analytics($admin)['revenue']['mrr']);
    }

    public function test_arr_is_twelve_times_mrr(): void
    {
        $admin = $this->admin();
        $payload = $this->analytics($admin);

        $this->assertSame(
            round((float) $payload['revenue']['mrr'] * 12, 2),
            (float) $payload['revenue']['arr'],
        );
    }

    public function test_expired_licence_is_counted_and_surfaced_for_attention(): void
    {
        $admin = $this->admin();
        $before = $this->analytics($admin)['licenses']['expired'];

        $license = $this->license($this->plan('dash-lapsed', 300, 'monthly'), now()->subDays(5)->toDateString());

        $payload = $this->analytics($admin);

        $this->assertSame($before + 1, $payload['licenses']['expired']);

        $subdomains = array_column($payload['attention'], 'subdomain');
        $this->assertContains(
            Tenant::find($license->tenant_id)->subdomain,
            $subdomains,
            'An expired licence must appear in the attention feed.',
        );
    }

    public function test_licence_expiring_within_thirty_days_is_flagged_but_still_counts_as_active(): void
    {
        $admin = $this->admin();
        $payload = $this->analytics($admin);
        $beforeSoon = $payload['licenses']['expiring_30_days'];
        $beforeActive = $payload['licenses']['active'];

        $this->license($this->plan('dash-soon', 400, 'monthly'), now()->addDays(10)->toDateString());

        $payload = $this->analytics($admin);

        $this->assertSame($beforeSoon + 1, $payload['licenses']['expiring_30_days']);
        $this->assertSame($beforeActive + 1, $payload['licenses']['active']);
    }

    public function test_payload_exposes_the_platform_currency(): void
    {
        $payload = $this->analytics($this->admin());

        $this->assertArrayHasKey('currency', $payload);
        $this->assertNotEmpty($payload['currency']);
    }

    public function test_growth_series_covers_twelve_months_and_is_cumulative(): void
    {
        $payload = $this->analytics($this->admin());

        $this->assertCount(12, $payload['growth']);

        $cumulative = array_column($payload['growth'], 'cumulative');
        $sorted = $cumulative;
        sort($sorted);

        $this->assertSame($sorted, $cumulative, 'Cumulative tenant count must never decrease.');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->postJson('/api/v1/central/dashboard/analytics')
            ->assertUnauthorized();
    }
}
