<?php

namespace Tests\Feature\Central;

use App\Domain\Tenancy\Entities\Tenant;
use App\Domain\Tenancy\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deleting a tenant leaves its schema on the server. Because the schema name is
 * derived from the subdomain, re-using a subdomain used to hand the new tenant the
 * previous sacco's members, branding and logo — migrations no-op because they are
 * already applied, and the seeder only tops up roles and settings.
 */
class TenantDatabaseReuseTest extends TestCase
{
    private string $database = 'sacco_reuse_probe_test';

    protected function tearDown(): void
    {
        DB::connection('master')->statement("DROP DATABASE IF EXISTS `{$this->database}`");

        parent::tearDown();
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant;
        $tenant->subdomain = 'reuse-probe';
        $tenant->database_name = $this->database;

        return $tenant;
    }

    public function test_provisioning_refuses_to_adopt_a_populated_existing_database(): void
    {
        $connection = DB::connection('master');
        $connection->statement("CREATE DATABASE IF NOT EXISTS `{$this->database}`");
        $connection->statement("CREATE TABLE `{$this->database}`.`members` (id INT PRIMARY KEY)");
        $connection->statement("INSERT INTO `{$this->database}`.`members` (id) VALUES (1)");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists and is not empty/');

        app(TenantService::class)->createPhysicalDatabase($this->tenant());
    }

    public function test_an_empty_existing_database_is_still_adopted(): void
    {
        // The remains of a failed create are safe to reuse; only populated schemas
        // carry another sacco's data.
        DB::connection('master')->statement("CREATE DATABASE IF NOT EXISTS `{$this->database}`");

        app(TenantService::class)->createPhysicalDatabase($this->tenant());

        $this->assertTrue(app(TenantService::class)->databaseExists($this->database));
    }

    public function test_a_fresh_database_is_created_when_none_exists(): void
    {
        DB::connection('master')->statement("DROP DATABASE IF EXISTS `{$this->database}`");

        app(TenantService::class)->createPhysicalDatabase($this->tenant());

        $service = app(TenantService::class);
        $this->assertTrue($service->databaseExists($this->database));
        $this->assertTrue($service->databaseIsEmpty($this->database));
    }
}
