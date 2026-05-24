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
                ->Leftjoin('plans as pl', 'pl.id', '=', 'ls.plan')
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
            $staus = $req['status'] != 'all' ? [$req['status']] : ['active', 'suspended', 'deleted'];
            $query = DB::table('tenants as ts')->select([
                ...$this->tenantDbFields,
                DB::raw("(SELECT expires_at FROM licenses WHERE tenant_id = ts.id AND status = 'active' ORDER BY expires_at DESC LIMIT 1) AS license_expires_at"),
            ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->tenantDbFields, $this->searchFields);
            }
            $dataCollection = $query->whereNull('deleted_at')->whereIn('status', $staus)->orderBy('created_at', 'DESC')->paginate($this->perpage());

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

                return $value;
            });

            return $dataCollection;
        });
    }
}
