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

    /**
     * Flat headline figures for callers that want the numbers without the series,
     * feeds and per-plan breakdown the full analytics payload carries.
     */
    public function summary()
    {
        $metrics = $this->DashbordsAnalysis();

        return response()->json([
            'currency' => $metrics['currency'],
            'total_tenants' => $metrics['tenants']['total'],
            'active_tenants' => $metrics['tenants']['active'],
            'expired_licenses' => $metrics['licenses']['expired'],
            'expiring_soon_3_days' => $metrics['licenses']['expiring_3_days'],
            'expiring_soon_7_days' => $metrics['licenses']['expiring_7_days'],
            'expiring_soon_30_days' => $metrics['licenses']['expiring_30_days'],
            'mrr' => $metrics['revenue']['mrr'],
            'arr' => $metrics['revenue']['arr'],
            // Long-standing key. It now carries the normalised figures alongside the
            // per-cycle breakdown rather than raw per-cycle sums.
            'revenue_metrics' => $metrics['revenue'],
        ]);
    }
}
