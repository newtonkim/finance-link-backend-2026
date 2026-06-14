<?php

namespace App\Central\Services;

use Illuminate\Support\Facades\DB;

class SettingService extends SettingUpdateOrCreateService
{
    protected array $permissionDbFields = [
        'id AS id',
        'action AS name',
        'parent_module AS module',
        'description AS description',
        'created_at AS created_at',
    ];

    protected array $rolesDbFields = [
        'rl.id AS id',
        'rl.name AS name',
        'rl.created_at AS created_at',
        'rl.description AS dec',
    ];

    protected array $plansDbFields = [
        'pl.id as id',
        'pl.price as cost',
        'pl.billing_cycle as billing_type',
        'pl.max_members as mx_mbrs',
        'pl.max_users as mxusrs',
        'pl.slug as slug',
        'pl.name as plan_name',
        'pl.created_at AS created_at',
    ];

    private $paginatedBy = 100;

    public function plansList()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $tenantCountSub = "
                (SELECT COUNT(lc.id) FROM licenses lc
                 WHERE lc.status = 'active'
                   AND (lc.plan = CAST(pl.id AS CHAR) OR lc.plan = pl.slug))
            ";

            $query = DB::table('plans as pl')
                ->select([
                    ...$this->plansDbFields,
                    'pl.features as features',
                    DB::raw("($tenantCountSub) as tenant_count"),
                    DB::raw("COALESCE(pl.price, 0) * ($tenantCountSub) as mrr_contribution"),
                ]);

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->plansDbFields);
            }

            return $query->whereNull('pl.deleted_at')->orderBy('pl.id', 'ASC')->paginate($this->paginatedBy);
        });
    }

    public function plansStats()
    {
        return $this->TryCatch(function () {
            $plans = DB::table('plans')->whereNull('deleted_at')->get(['id', 'slug', 'price', 'billing_cycle']);
            $totalPlans = $plans->count();

            $activeSubscribers = DB::table('licenses')
                ->where('status', 'active')
                ->distinct('tenant_id')
                ->count('tenant_id');

            $mrr = 0;
            foreach ($plans as $plan) {
                $tenantCount = DB::table('licenses')
                    ->where('status', 'active')
                    ->where(function ($q) use ($plan) {
                        $q->where('plan', (string) $plan->id)->orWhere('plan', $plan->slug);
                    })
                    ->count();

                $monthlyPrice = match ($plan->billing_cycle ?? 'monthly') {
                    'weekly'  => (float) $plan->price * 4.33,
                    'monthly' => (float) $plan->price,
                    'quarterly' => (float) $plan->price / 3,
                    'annual', 'yearly' => (float) $plan->price / 12,
                    default   => (float) $plan->price,
                };
                $mrr += $monthlyPrice * $tenantCount;
            }

            $avgPerTenant = $activeSubscribers > 0 ? round($mrr / $activeSubscribers, 2) : 0;

            return [
                'total_plans'        => $totalPlans,
                'active_subscribers' => $activeSubscribers,
                'mrr'                => round($mrr, 2),
                'avg_per_tenant'     => $avgPerTenant,
            ];
        });
    }

    public function featuresList()
    {
        return $this->TryCatch(function () {
            return DB::connection('master')
                ->table('plan_features')
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->orderBy('id')
                ->get(['id', 'key', 'name']);
        });
    }

    public function plansDropDown()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('plans as pl')->select(['id', DB::raw("REPLACE(pl.slug,'-',' ') AS name"), 'price As cost', 'billing_cycle As billing_type']);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->plansDbFields);
            }

            return $query->whereNull('pl.deleted_at')->orderBy('id', 'DESC')->paginate($this->paginatedBy);
        });
    }

    public function plansDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $plans = DB::table('plans as pl')->where('id', $req->id)->first([
                'pl.id as id',
                'pl.features as features',
                ...$this->plansDbFields,
            ]);
            $plans->features = $this->isJSONToArray(json_decode($plans->features, true));

            return $plans;
        });
    }

    public function permissionDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $permission = DB::table('permissions')->where('id', $req->id)->first($this->permissionDbFields);

            return $permission;
        });
    }

    public function permissionsDropdown()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('permissions')->select(['id',     DB::raw("REPLACE(action,'-',' ') AS name")]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->permissionDbFields);
            }

            return $query->whereNull('deleted_at')->orderBy('created_at', 'DESC')->paginate($this->paginatedBy);
        });
    }

    public function permissionsListCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('permissions')->select([
                ...$this->permissionDbFields,
            ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->permissionDbFields);
            }
            $dataCollection = $query->whereNull('deleted_at')
                ->orderBy('created_at', 'DESC')->paginate($this->paginatedBy);

            return $dataCollection;
        });
    }

    public function UserAttachedToRoleList()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('roles As rl')
                ->join('platform_users As pu', 'rl.id', '=', 'pu.role_id')
                ->select([
                    ...$this->rolesDbFields,
                    'pu.name As staff_name',
                    'pu.id As staff_id',
                ])->where('rl.id', $req->id);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->permissionDbFields);
            }
            $dataCollection = $query->whereNull('rl.deleted_at')
                ->orderBy('rl.created_at', 'DESC')->paginate($this->paginatedBy);

            return $dataCollection;
        });
    }

    public function RolesListCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('roles As rl')->select([
                ...$this->rolesDbFields,
            ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->permissionDbFields);
            }
            $dataCollection = $query->whereNull('deleted_at')
                ->orderBy('created_at', 'DESC')->paginate($this->paginatedBy);

            return $dataCollection;
        });
    }

    public function brandingDetails()
    {
        return $this->TryCatch(function () {
            $branding = DB::connection('master')->table('central_branding')->first();
            if ($branding && $branding->logo_path) {
                $branding->logo_url = asset('storage/'.$branding->logo_path);
            }

            return $branding;
        });
    }

    public function permissionListHolders()
    {
        $req = request()->all();

        return $this->TryCatch(function () use ($req) {
            $dataCollection = DB::table('permissions_users')->select([
                'pu.name As staff_name',
                'pu.id As staff_id',
            ])->join('platform_users as pu', 'pu.id', '=', 'permissions_users.user_id')
                ->whereNull('pu.deleted_at')
                ->whereJsonContains('permission_ids', (int) $req['id'])
                ->orderBy('created_at', 'DESC')->paginate($this->paginatedBy);

            return $dataCollection;
        });
    }
}
