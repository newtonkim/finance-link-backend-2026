<?php

namespace App\Central\Services;

use App\Central\Services\TenantsService as ServicesTenantsService;
use App\Domain\Tenancy\Entities\Tenant;
use App\Http\Globals\GlobalHelpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantsUpdateOrCreateService extends GlobalHelpers
{
    /**
     * Deleting a tenant used to leave its database on the server. Because the
     * database name is derived from the subdomain, re-creating a tenant with that
     * same subdomain would silently adopt the old schema along with its members,
     * branding and logo.
     *
     * A soft delete still leaves the tenant recoverable, so the schema is kept. A
     * permanent delete removes the row for good, and the schema goes with it.
     */
    public function tenantsDelete()
    {
        $request = request();
        $permanent = ! empty($request->permanent_delete);

        $tenant = $permanent ? Tenant::withTrashed()->find($request->id) : null;

        $this->DeleteRecord('tenants', $request);

        if ($tenant) {
            $this->dropTenantDatabase($tenant);
        }

        $tenantsService = app(ServicesTenantsService::class);

        return $tenantsService->tenantsListCollection();
    }

    /**
     * Drops a permanently deleted tenant's schema.
     *
     * The name comes from our own tenants table, but it is interpolated into SQL and
     * the statement is irreversible, so it is checked against the expected shape and
     * against the central database before it runs. A failure here is logged rather
     * than thrown: the tenant record is already gone, and an orphaned schema is a
     * cleanup task, not a reason to fail the request.
     */
    private function dropTenantDatabase(Tenant $tenant): void
    {
        $database = (string) $tenant->database_name;
        $central = (string) config('database.connections.master.database');

        if ($database === '' || $database === $central || ! preg_match('/^sacco_[A-Za-z0-9_]+$/', $database)) {
            Log::warning("Refusing to drop tenant database [{$database}] for {$tenant->subdomain}: unexpected name.");

            return;
        }

        try {
            DB::connection('master')->statement("DROP DATABASE IF EXISTS `{$database}`");
            Log::info("Dropped database [{$database}] for permanently deleted tenant {$tenant->subdomain}.");
        } catch (\Throwable $e) {
            Log::error("Failed to drop database [{$database}] for {$tenant->subdomain}: ".$e->getMessage());
        }
    }
}
