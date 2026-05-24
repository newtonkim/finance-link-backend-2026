<?php

namespace App\Infrastructure\Tenancy;

use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DatabaseSwitcher
{
    /**
     * Switch the application's 'tenant' connection to the given tenant's database.
     */
    public function switch(Tenant $tenant): void
    {
        $currentDb = Config::get('database.connections.tenant.database');
        
        // If we are already on this database, don't purge.
        // This is critical for testing where we share a single transaction-wrapped PDO.
        if ($currentDb === $tenant->database_name && !empty($currentDb)) {
            DB::setDefaultConnection('tenant');
            return;
        }

        // 1. Purge the existing 'tenant' connection to clear out any old state
        DB::purge('tenant');

        // 2. Dynamically set the database name for the 'tenant' connection
        Config::set('database.connections.tenant.database', $tenant->database_name);

        // 3. Reconnect to the database to apply the new configuration
        DB::reconnect('tenant');

        // Optional: Set 'tenant' as default
        DB::setDefaultConnection('tenant');
    }

    /**
     * Reset the tenant connection to clear sensitive data or prepare for next resolution.
     */
    public function purge(): void
    {
        DB::purge('tenant');
        Config::set('database.connections.tenant.database', null);
    }
}
