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
     * Delete a tenant and everything belonging to it.
     *
     * Deleting a tenant removes its licences, its record, and its database. This is
     * irreversible: every member, account, loan and journal line that sacco owned is
     * destroyed with the schema. Take a mysqldump first if the data might be wanted.
     *
     * The database has to go with the record. A tenant's database name is derived
     * from its subdomain, so a schema left behind is inherited by the next tenant
     * that registers the same subdomain — which is how a new sacco ended up opening
     * onto a previous sacco's members and branding.
     */
    public function tenantsDelete()
    {
        $request = request();

        $tenant = Tenant::withTrashed()->find($request->id);

        if (! $tenant) {
            // Fall back to the generic delete so the response shape is unchanged.
            $this->DeleteRecord('tenants', $request);

            return app(ServicesTenantsService::class)->tenantsListCollection();
        }

        $database = (string) $tenant->database_name;
        $subdomain = (string) $tenant->subdomain;

        // The record goes first: if dropping the schema fails, the tenant is still
        // gone and the leftover schema is a cleanup task rather than a live tenant
        // pointing at a half-deleted database.
        DB::connection('master')->transaction(function () use ($tenant) {
            $tenant->licenses()->delete();
            $tenant->forceDelete();
        });

        Log::info("Deleted tenant {$subdomain} and its licences.");

        $this->dropTenantDatabase($subdomain, $database);

        return app(ServicesTenantsService::class)->tenantsListCollection();
    }

    /** Schemas that are never a tenant's, whatever the tenants table claims. */
    private const PROTECTED_SCHEMAS = ['mysql', 'information_schema', 'performance_schema', 'sys'];

    /**
     * Drop a deleted tenant's schema.
     *
     * The name comes from our own tenants table, but it is interpolated into SQL and
     * the statement is irreversible, so it is validated first: a bare identifier,
     * not the central database, and not a MySQL system schema.
     *
     * It deliberately does not require the current 'sacco_' prefix. That prefix is
     * TenantService's business, and duplicating it here would mean a future rename
     * silently stopped every drop — leaving orphaned schemas behind again with
     * nothing but a warning in the log.
     *
     * A failure is logged rather than thrown: the tenant record is already gone by
     * this point, and failing the request would imply the deletion did not happen.
     */
    private function dropTenantDatabase(string $subdomain, string $database): void
    {
        $central = (string) config('database.connections.master.database');

        $unsafe = $database === ''
            || $database === $central
            || in_array(strtolower($database), self::PROTECTED_SCHEMAS, true)
            || ! preg_match('/^[A-Za-z0-9_]+$/', $database);

        if ($unsafe) {
            Log::warning("Refusing to drop tenant database [{$database}] for {$subdomain}: unsafe or protected name.");

            return;
        }

        try {
            DB::connection('master')->statement("DROP DATABASE IF EXISTS `{$database}`");
            Log::info("Dropped database [{$database}] for deleted tenant {$subdomain}.");
        } catch (\Throwable $e) {
            Log::error("Failed to drop database [{$database}] for {$subdomain}: ".$e->getMessage());
        }
    }
}
