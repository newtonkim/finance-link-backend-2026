<?php

namespace App\Central\Services;

use App\Central\Models\License;
use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LicenseService extends LicenseUpdateOrCreateService
{
    protected array $LicenseDbFields = [
        'ls.id AS id',

        'ts.name AS tenant_name',
        'ls.starts_at AS starts',
        'ls.expires_at AS expires',
        'ls.grace_ends_at AS grace_ends',
        // "ls.max_members AS members",
        // "ls.max_users AS users",
        'ls.status AS status',
        'ls.created_at AS created_at',
    ];

    public function licensesListCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $staus = $req['status'] != 'all' ? [$req['status']] : ['active', 'suspended', 'expired', 'trial'];
            $query = DB::table('licenses as ls')
                ->Join('tenants as ts', 'ls.tenant_id', '=', 'ts.id')
                ->leftJoin('plans as pl', 'pl.id', '=', 'ls.plan')
                ->select([...$this->LicenseDbFields, DB::raw('IFNULL(pl.name, ls.plan) AS plan')]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->LicenseDbFields);
            }

            return $query
                // ->whereNull("deleted_at")
                ->whereIn('ls.status', $staus)
                ->orderBy('ls.created_at', 'DESC')->paginate($this->perpage());
        });
    }

    public function licensesEditDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('licenses as ls')
                ->Join('tenants as ts', 'ls.tenant_id', '=', 'ts.id')
                ->leftJoin('plans as pl', 'pl.id', '=', 'ls.plan')
                ->select([
                    // ...$this->LicenseDbFields,
                    'ls.id as id',
                    'ts.id as tenant_id',
                    'pl.id as plan_id',
                    'ls.starts_at AS starts',
                    'ls.expires_at AS expires',
                    'ls.status AS status',
                ]);

            $data = $query->whereRaw('ls.id=?', [$req->id])
                ->first();

            return $data;
        });
    }

    public function licensesDetailsCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('licenses as ls')
                ->Join('tenants as ts', 'ls.tenant_id', '=', 'ts.id')
                ->leftJoin('plans as pl', 'pl.id', '=', 'ls.plan')
                ->select([
                    ...$this->LicenseDbFields,
                    'pl.max_users As mxusrs',
                    'pl.max_members as mx_mbrs',
                    'pl.billing_cycle as billing_type',
                    'pl.features as features',
                    'pl.slug as plan_slug',
                    'pl.price as cost',
                    DB::raw('IFNULL(pl.name, ls.plan) AS plan_name'),
                ]);

            $data = $query->whereRaw('ls.id=?', [$req->id])
                ->first();
            $data->features = $this->isJSONToArray(json_decode($data->features, true)); // i have done that i expect un perfect data  some data

            return $data;
        });
    }

    public function assignLicense(Tenant $tenant, string $planSlug, int $durationMonthsMonth = 12): License
    {
        return License::create([
            'tenant_id' => $tenant->id,
            'plan' => $planSlug,
            'starts_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays($durationMonthsMonth),
            'status' => 'active',
        ]);
    }

    /**
     * Suspend the tenant's license.
     */
    public function suspend(Tenant $tenant): void
    {
        License::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->update(['status' => 'suspended']);
    }

    /**
     * Renew/Activate the tenant's license with a new plan and duration.
     */
    public function renew(Tenant $tenant, string $planSlug, int $days): void
    {
        License::where('tenant_id', $tenant->id)->update(['status' => 'expired']); // Deactivate current

        License::create([
            'tenant_id' => $tenant->id,
            'plan' => $planSlug,
            'starts_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addDays($days),
            'status' => 'active',
        ]);
    }

    /**
     * Extend the grace period by pushing the expiry date.
     */
    public function extendGrace(Tenant $tenant, int $days): void
    {
        $license = License::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->firstOrFail();

        $license->update([
            'expires_at' => $license->expires_at->addDays($days),
        ]);
    }

    /**
     * Calculate revenue metrics for the central dashboard.
     */
    public function getRevenueMetrics(): array
    {
        $mrr = DB::connection('master')
            ->table('licenses')
            ->join('plans', 'licenses.plan', '=', 'plans.slug')
            ->where('licenses.status', 'active')
            ->sum('plans.price');

        return [
            'monthly_recurring_revenue' => (float) $mrr,
            'annual_recurring_revenue' => (float) ($mrr * 12),
        ];
    }

    /**
     * Get the count of licenses expiring within a given range.
     */
    public function getExpiringSoonCount(int $days = 3): int
    {
        return License::where('status', 'active')
            ->whereBetween('expires_at', [Carbon::now(), Carbon::now()->addDays($days)])
            ->count();
    }
}
