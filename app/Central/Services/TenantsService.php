<?php

namespace App\Central\Services;

use Illuminate\Support\Facades\DB;

class TenantsService extends TenantsUpdateOrCreateService
{
    protected array $tenantDbFields = [
        'ts.id AS id',
        'ts.name AS sacco_name',
        'ts.subdomain AS sacco_domain',
        'ts.domain AS host_domain',
        'ts.database_name AS storage',
        'ts.status AS status',
        'ts.settings AS cogs',
        'ts.created_at AS created_at',
    ];

    protected array $searchFields = [
        'Host Name' => 'domain',
        'Database' => 'database_name',
        'sacco name' => 'name',
        'Sacco Domain' => 'subdomain',
        'status' => 'status',
        'created Date' => 'created_at',
    ];

    public function tenantDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {

            $tenant = DB::table('tenants AS ts')
                ->Leftjoin('licenses as ls', 'ls.tenant_id', '=', 'ts.id')
                ->Leftjoin('plans as pl', 'pl.id', '=', 'ls.plan_id')
                ->orderBy('ts.created_at', 'DESC')
                ->where('ts.id', $req->id)->first([
                    ...$this->tenantDbFields,
                    'ls.id As license_id',
                    'pl.max_users As mxusrs',
                    'pl.max_members as mx_mbrs',
                    'pl.billing_cycle as billing_type',
                    'pl.features as features',
                    'pl.slug as plan_slug',
                    'pl.price as cost',
                    'pl.id as plan_id',
                    DB::raw('IFNULL(pl.name, ls.plan) AS plan_name'),

                ]);
            $tenant->features = $this->isJSONToArray(json_decode($tenant->features, true));

            return $tenant;
        });
    }

    public function tenantsDropdown()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('tenants AS ts')->select(['id', 'name']);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->tenantDbFields, $this->searchFields);
            }

            return $query->whereNull('deleted_at')->orderBy('created_at', 'DESC')->paginate($this->perpage());
        });
    }

    public function tenantsListCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $statuses = $req['status'] != 'all' ? [$req['status']] : ['active', 'suspended', 'deleted'];
            $latestLicenseId = <<<'SQL'
                (SELECT current_license.id
                 FROM licenses AS current_license
                 WHERE current_license.tenant_id = ts.id
                 ORDER BY CASE WHEN current_license.status = 'active' THEN 0 ELSE 1 END,
                          current_license.expires_at DESC,
                          current_license.created_at DESC
                 LIMIT 1)
                SQL;

            $query = DB::table('tenants as ts')
                ->leftJoin('licenses as ls', 'ls.id', '=', DB::raw($latestLicenseId))
                ->leftJoin('plans as pl', 'pl.id', '=', 'ls.plan_id')
                ->select([
                    ...$this->tenantDbFields,
                    'ls.expires_at AS license_expires_at',
                    'ls.id AS license_id',
                    'pl.id AS plan_id',
                    'pl.slug AS plan_slug',
                    'pl.price AS cost',
                    'pl.billing_cycle AS billing_type',
                    DB::raw('COALESCE(ls.max_members, pl.max_members) AS mx_mbrs'),
                    DB::raw('COALESCE(ls.max_users, pl.max_users) AS mxusrs'),
                    DB::raw('COALESCE(ls.features, pl.features) AS features'),
                    DB::raw('COALESCE(pl.name, ls.plan) AS plan_name'),
                ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->tenantDbFields, $this->searchFields);
            }
            $dataCollection = $query->whereNull('ts.deleted_at')->whereIn('ts.status', $statuses)->orderBy('ts.created_at', 'DESC')->paginate($this->perpage());

            // Dynamically determine the frontend base URL based on the request origin or host
            $baseUrl = request()->header('Origin') ?? request()->header('Referer') ?? env('FRONTEND_URL', 'http://localhost:3000');
            $parsed = parse_url(rtrim($baseUrl, '/'));
            $scheme = $parsed['scheme'] ?? 'http';
            $host = $parsed['host'] ?? 'localhost';
            $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

            // If the host is an API domain, strip the 'api.' prefix for the tenant link
            if (str_starts_with($host, 'api.')) {
                $host = substr($host, 4);
            }

            $dataCollection->getCollection()->transform(function ($value) use ($scheme, $host, $port) {
                $value->url = "{$scheme}://{$value->sacco_domain}.{$host}{$port}/tenant/login";
                $value->cogs = json_decode($value->cogs);
                $value->features = $value->features ? json_decode($value->features, true) : null;

                return $value;
            });

            return $dataCollection;
        });
    }
}
