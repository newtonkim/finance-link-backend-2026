<?php

namespace App\Central\Services;

use App\Http\Globals\GlobalHelpers;
use Illuminate\Support\Facades\DB;

class DashboardService extends GlobalHelpers
{
    public function DashbordsAnalysis()
    {
        $callTenantAnalysis = DB::table('tenants')->select([
            DB::raw('COUNT(*) as tatal_tenants'),
            DB::raw('SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active_tenants'),
            DB::raw('SUM(CASE WHEN status = "suspended" THEN 1 ELSE 0 END) as suspended_tenants'),
        ])->first();

        $callLicenseAnalysis = DB::select('
    SELECT 
        COUNT(*) as total_licenses,
        SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END) as active_licenses,
        SUM(CASE WHEN expires_at < NOW() THEN 1 ELSE 0 END) as expired_licenses,
        SUM(CASE WHEN expires_at BETWEEN NOW() AND NOW() + INTERVAL 3 DAY THEN 1 ELSE 0 END) as expiring_soon_3_days
    FROM licenses
');
        $callrevenueAnalysis = DB::table('licenses as ls')
            ->join('plans as pl', 'ls.plan_id', '=', 'pl.id')
            ->select([
                DB::raw('SUM(pl.price) as total_revenue'),
                DB::raw('pl.billing_cycle AS billing_type'),
            ])
            ->groupBy('pl.billing_cycle')
            ->whereIn('pl.billing_cycle', ['monthly', 'yearly', 'weekly'])->get();

        foreach ($callrevenueAnalysis as $key => $value) {
            $callrevenueAnalysis[$value->billing_type] = $value->total_revenue;
            unset($callrevenueAnalysis[$key]);
        }

        $metrics = [
            'revenue' => $callrevenueAnalysis,
            'tenants' => $callTenantAnalysis,
            'licenses' => $callLicenseAnalysis[0],
        ];

        return $metrics;
    }
}
