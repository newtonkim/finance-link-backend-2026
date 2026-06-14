<?php

namespace Tests\Feature\Central;

use App\Central\Models\License;
use App\Central\Models\LicenseInvoice;
use App\Central\Models\LicensePayment;
use App\Central\Models\LicensePaymentAttempt;
use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use App\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class LicenseRenewalBillingTest extends TestCase
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

    public function test_renewal_creates_pending_billing_and_confirmation_activates_license(): void
    {
        [$admin, $tenant, $plan, $license] = $this->billingFixture();

        $renewal = $this->withHeaders([
            'X-Forwarded-Host' => 'central.mfukopro.test',
            'Idempotency-Key' => 'renewal-test-'.$license->id,
        ])
            ->actingAs($admin, 'platform')
            ->postJson('/api/v1/central/licenses/renew', [
                'id' => $license->id,
                'plan_id' => $plan->id,
                'billing_cycle' => 'monthly',
                'payment_method' => 'bank',
                'provider' => 'manual',
            ]);

        $renewal->assertOk();
        $renewal->assertJsonPath('payload.invoice_status', LicenseInvoice::STATUS_INVOICE_PENDING);
        $renewal->assertJsonPath('payload.payment_status', LicensePayment::STATUS_PAYMENT_PENDING);
        $this->assertSame(1, License::where('tenant_id', $tenant->id)->where('status', 'active')->count());
        $this->assertSame(1, LicensePaymentAttempt::query()->count());

        $invoiceId = $renewal->json('payload.invoice_id');
        $paymentId = $renewal->json('payload.payment_id');

        $confirmation = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->actingAs($admin, 'platform')
            ->postJson('/api/v1/central/licenses/confirm-payment', [
                'invoice_id' => $invoiceId,
                'payment_id' => $paymentId,
                'approval_type' => 'manual',
                'provider_reference' => 'MANUAL-'.$paymentId,
            ]);

        $confirmation->assertOk();
        $confirmation->assertJsonPath('payload.invoice_status', LicenseInvoice::STATUS_LICENSE_ACTIVATED);
        $confirmation->assertJsonPath('payload.payment_status', LicensePayment::STATUS_PAYMENT_CONFIRMED);
        $this->assertSame(1, License::where('tenant_id', $tenant->id)->where('status', 'active')->count());

        // The existing license is renewed in place — no duplicate row is created,
        // and the same license now reads active with the new expiry.
        $this->assertSame(1, License::where('tenant_id', $tenant->id)->count());
        $this->assertSame($license->id, $confirmation->json('payload.new_license_id'));
        $renewed = License::find($license->id);
        $this->assertSame('active', $renewed->status);
        $this->assertTrue($renewed->expires_at->isFuture());
        $this->assertDatabaseHas('license_invoices', [
            'id' => $invoiceId,
            'status' => LicenseInvoice::STATUS_LICENSE_ACTIVATED,
            'plan_id' => $plan->id,
            'tenant_id' => $tenant->id,
        ], 'master');
        $this->assertDatabaseHas('license_payments', [
            'id' => $paymentId,
            'license_invoice_id' => $invoiceId,
            'provider_reference' => 'MANUAL-'.$paymentId,
            'status' => LicensePayment::STATUS_PAYMENT_CONFIRMED,
        ], 'master');
        $this->assertSame(2, LicensePaymentAttempt::query()->count());
    }

    public function test_duplicate_renewal_with_same_idempotency_key_returns_existing_invoice(): void
    {
        [$admin, , , $license] = $this->billingFixture();
        $idempotencyKey = 'renewal-duplicate-'.$license->id;
        $payload = [
            'id' => $license->id,
            'billing_cycle' => 'monthly',
            'payment_method' => 'bank',
            'provider' => 'manual',
        ];

        $first = $this->withHeaders([
            'X-Forwarded-Host' => 'central.mfukopro.test',
            'Idempotency-Key' => $idempotencyKey,
        ])
            ->actingAs($admin, 'platform')
            ->postJson('/api/v1/central/licenses/renew', $payload);

        $second = $this->withHeaders([
            'X-Forwarded-Host' => 'central.mfukopro.test',
            'Idempotency-Key' => $idempotencyKey,
        ])
            ->actingAs($admin, 'platform')
            ->postJson('/api/v1/central/licenses/renew', $payload);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('payload.invoice_id'), $second->json('payload.invoice_id'));
        $this->assertSame($first->json('payload.payment_id'), $second->json('payload.payment_id'));
        $this->assertSame(1, LicenseInvoice::where('idempotency_key', $idempotencyKey)->count());
        $this->assertSame(1, LicensePayment::where('idempotency_key', $idempotencyKey)->count());
        $this->assertSame(1, LicensePaymentAttempt::where('idempotency_key', $idempotencyKey)->count());
    }

    public function test_mobile_money_renewal_rejects_non_ugx_currency(): void
    {
        [$admin, , , $license] = $this->billingFixture();

        $response = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->actingAs($admin, 'platform')
            ->postJson('/api/v1/central/licenses/renew', [
                'id' => $license->id,
                'billing_cycle' => 'monthly',
                'payment_method' => 'mobile_money',
                'provider' => 'mtn',
                'currency' => 'USD',
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, LicenseInvoice::query()->count());
    }

    public function test_card_renewal_snapshots_fx_rate_for_converted_charge(): void
    {
        [$admin, , , $license] = $this->billingFixture();

        DB::connection('master')->table('central_currency_settings')->insert([
            'default_currency' => 'USD',
            'enabled_currencies' => json_encode(['USD', 'UGX']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->actingAs($admin, 'platform')
            ->postJson('/api/v1/central/licenses/renew', [
                'id' => $license->id,
                'billing_cycle' => 'monthly',
                'payment_method' => 'card',
                'provider' => 'visa',
                'currency' => 'UGX',
            ]);

        $response->assertOk();
        $response->assertJsonPath('payload.base_currency', 'USD');
        $response->assertJsonPath('payload.charge_currency', 'UGX');

        $invoiceId = $response->json('payload.invoice_id');

        // Plan price (1000) is settled in USD and charged in UGX at the
        // reference rate of 3735, snapshotted on both records.
        $this->assertDatabaseHas('license_invoices', [
            'id' => $invoiceId,
            'currency' => 'USD',
            'charge_currency' => 'UGX',
            'total' => 1000.00,
            'charge_total' => 3735000.00,
        ], 'master');

        $this->assertDatabaseHas('license_payments', [
            'license_invoice_id' => $invoiceId,
            'currency' => 'UGX',
            'base_currency' => 'USD',
            'amount' => 3735000.00,
            'base_amount' => 1000.00,
        ], 'master');
    }

    public function test_usd_plan_price_is_converted_to_settlement_currency(): void
    {
        // No currency settings row → base currency defaults to UGX, while the
        // plan price (1000) is authored in USD.
        [$admin, , , $license] = $this->billingFixture();

        $response = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->actingAs($admin, 'platform')
            ->postJson('/api/v1/central/licenses/renew', [
                'id' => $license->id,
                'billing_cycle' => 'monthly',
                'payment_method' => 'bank',
                'provider' => 'manual',
            ]);

        $response->assertOk();
        $response->assertJsonPath('payload.currency', 'UGX');

        $invoiceId = $response->json('payload.invoice_id');

        // 1000 USD settled in UGX at the reference rate of 3735 = 3,735,000 UGX.
        $this->assertDatabaseHas('license_invoices', [
            'id' => $invoiceId,
            'currency' => 'UGX',
            'total' => 3735000.00,
            'charge_total' => 3735000.00,
        ], 'master');
    }

    public function test_invoice_can_be_downloaded_as_pdf(): void
    {
        [$admin, , , $license] = $this->billingFixture();

        $renewal = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->actingAs($admin, 'platform')
            ->postJson('/api/v1/central/licenses/renew', [
                'id' => $license->id,
                'billing_cycle' => 'monthly',
                'payment_method' => 'bank',
                'provider' => 'manual',
            ]);

        $invoiceId = $renewal->json('payload.invoice_id');

        $download = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->actingAs($admin, 'platform')
            ->post('/api/v1/central/licenses/invoice/download', ['invoice_id' => $invoiceId]);

        $download->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('content-type'));
    }

    private function billingFixture(): array
    {
        $admin = PlatformUser::create([
            'name' => 'Billing Admin',
            'email' => 'billing-admin-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);

        $tenant = Tenant::factory()->create();
        $plan = Plan::firstOrCreate(['slug' => 'renewal-basic'], [
            'name' => 'Renewal Basic',
            'price' => 1000,
            'billing_cycle' => 'monthly',
            'days' => 30,
        ]);

        $license = License::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'plan' => (string) $plan->id,
            'starts_at' => now()->subMonth()->toDateString(),
            'expires_at' => now()->addDay()->toDateString(),
            'status' => 'active',
        ]);

        return [$admin, $tenant, $plan, $license];
    }
}
