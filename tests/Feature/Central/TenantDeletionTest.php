<?php

namespace Tests\Feature\Central;

use App\Central\Models\License;
use App\Central\Models\Plan;
use App\Central\Services\TenantsUpdateOrCreateService;
use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deleting a tenant destroys everything that tenant owned, schema included. The
 * schema must go with the record: its name is derived from the subdomain, so one
 * left behind is inherited by the next tenant that registers the same subdomain.
 */
class TenantDeletionTest extends TestCase
{
    private array $schemas = [];

    protected function tearDown(): void
    {
        foreach ($this->schemas as $schema) {
            DB::connection('master')->statement("DROP DATABASE IF EXISTS `{$schema}`");
        }

        parent::tearDown();
    }

    private function tenantWithDatabase(): Tenant
    {
        $tenant = Tenant::factory()->create();

        // Factories do not create the physical schema; this test is about its removal.
        $schema = $tenant->database_name;
        $this->schemas[] = $schema;

        DB::connection('master')->statement("CREATE DATABASE IF NOT EXISTS `{$schema}`");
        DB::connection('master')->statement("CREATE TABLE `{$schema}`.`members` (id INT PRIMARY KEY)");

        return $tenant;
    }

    private function schemaExists(string $schema): bool
    {
        return DB::connection('master')->selectOne(
            'SELECT 1 AS present FROM information_schema.schemata WHERE schema_name = ?',
            [$schema],
        ) !== null;
    }

    private function deleteTenant(Tenant $tenant): void
    {
        request()->merge(['id' => $tenant->id]);
        app(TenantsUpdateOrCreateService::class)->tenantsDelete();
    }

    public function test_deleting_a_tenant_drops_its_database(): void
    {
        $tenant = $this->tenantWithDatabase();
        $this->assertTrue($this->schemaExists($tenant->database_name));

        $this->deleteTenant($tenant);

        $this->assertFalse(
            $this->schemaExists($tenant->database_name),
            'The schema must not survive the tenant, or the next tenant on this subdomain inherits it.',
        );
    }

    public function test_deleting_a_tenant_removes_the_record_entirely(): void
    {
        $tenant = $this->tenantWithDatabase();

        $this->deleteTenant($tenant);

        $this->assertNull(Tenant::withTrashed()->find($tenant->id));
    }

    public function test_deleting_a_tenant_removes_its_licences(): void
    {
        $tenant = $this->tenantWithDatabase();

        $plan = Plan::firstOrCreate(['slug' => 'deletion-probe'], [
            'name' => 'Deletion Probe',
            'price' => 100,
            'billing_cycle' => 'monthly',
            'days' => 30,
        ]);

        License::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'plan' => (string) $plan->id,
            'starts_at' => now()->subMonth()->toDateString(),
            'expires_at' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $this->deleteTenant($tenant);

        $this->assertSame(0, License::where('tenant_id', $tenant->id)->count());
    }

    public function test_a_protected_system_schema_is_never_dropped(): void
    {
        $tenant = Tenant::factory()->create();
        $tenant->database_name = 'mysql';
        $tenant->save();

        $this->deleteTenant($tenant);

        $this->assertTrue($this->schemaExists('mysql'), 'A system schema must never be dropped.');
    }

    public function test_the_central_database_is_never_dropped(): void
    {
        $central = config('database.connections.master.database');

        $tenant = Tenant::factory()->create();
        $tenant->database_name = $central;
        $tenant->save();

        $this->deleteTenant($tenant);

        $this->assertTrue(
            $this->schemaExists($central),
            'A tenant row pointing at the central database must never cause it to be dropped.',
        );
    }
}
