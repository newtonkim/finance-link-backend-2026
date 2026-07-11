<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantService
{
    public function __construct(
        protected DatabaseSwitcher $switcher
    ) {}

    /**
     * Step 2 — Create Tenant Record (Master DB)
     */
    public function createTenantRecord(string $name, string $subdomain): Tenant
    {
        $slug = Str::slug($subdomain, '_');
        $dbName = 'sacco_'.$slug;

        // Guard: never let a tenant database collide with the central/master DB,
        // and never create an empty/degenerate name (e.g. blank subdomain).
        $centralDb = config('database.connections.master.database');
        if ($slug === '' || $dbName === $centralDb) {
            throw new \InvalidArgumentException(
                "Invalid tenant subdomain [{$subdomain}]: resolved database [{$dbName}] "
                ."is empty or collides with the central database [{$centralDb}]."
            );
        }

        return Tenant::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'subdomain' => $subdomain,
            'database_name' => $dbName,
            'status' => 'active',
        ]);
    }

    /**
     * Step 3 — Create Tenant Database
     */
    public function createPhysicalDatabase(Tenant $tenant): void
    {
        DB::connection('master')->statement("CREATE DATABASE IF NOT EXISTS `{$tenant->database_name}`");
    }

    /**
     * Drop the tenant's physical database. Used by provisioning rollback so a failed
     * tenant creation can be retried with the same subdomain.
     */
    public function dropPhysicalDatabase(Tenant $tenant): void
    {
        DB::connection('master')->statement("DROP DATABASE IF EXISTS `{$tenant->database_name}`");
    }

    /**
     * Step 4 — Run Tenant Migrations
     */
    public function runMigrations(Tenant $tenant): void
    {
        Artisan::call('migrate', [
            '--database' => 'tenant', // This connection needs to be pointed to the new DB first or use the switcher
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    /**
     * Step 5 — Switch to Tenant DB
     */
    public function switchToTenant(Tenant $tenant): void
    {
        $this->switcher->switch($tenant);
    }
}
