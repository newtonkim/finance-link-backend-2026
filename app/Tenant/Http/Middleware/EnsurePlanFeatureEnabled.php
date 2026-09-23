<?php

namespace App\Tenant\Http\Middleware;

use App\Central\Models\License;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if (! $tenant) {
            abort(403, 'Tenant context is required.');
        }

        $license = License::query()
            ->with('plan')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trial', 'grace'])
            ->orderByDesc('expires_at')
            ->first();

        $plan = $license?->getRelation('plan');

        if (! $license || ! $plan) {
            abort(403, 'No active license plan is available for this tenant.');
        }

        $enabled = $plan->hasFeature($feature);

        if (! $enabled) {
            return response()->json([
                'message' => $feature === 'reports'
                    ? 'Reports are disabled for this tenant plan. Ask your central administrator to enable Reports (Monthly statements) in the plan settings.'
                    : 'This feature is not enabled for the tenant plan.',
                'feature' => $feature,
            ], 403);
        }

        return $next($request);
    }
}
