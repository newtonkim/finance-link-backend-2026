<?php

namespace App\Central\Http\Controllers;

use App\Central\Services\DashboardService;
use App\Central\Services\LicenseService;

class DashboardController extends DashboardService
{
    public function __construct(
        protected LicenseService $licenseService
    ) {}

    public function dashboard_analytics()
    {
        return $this->Response(['data' => $this->DashbordsAnalysis()]);
    }

    public function summary()
    {
        $metrics = $this->DashbordsAnalysis();

        return response()->json([
            'total_tenants' => $metrics['tenants']->tatal_tenants,
            'active_tenants' => $metrics['tenants']->active_tenants,
            'expired_licenses' => $metrics['licenses']->expired_licenses,
            'expiring_soon_3_days' => $metrics['licenses']->expiring_soon_3_days,
            'revenue_metrics' => $metrics['revenue'],
        ]);
    }
}
