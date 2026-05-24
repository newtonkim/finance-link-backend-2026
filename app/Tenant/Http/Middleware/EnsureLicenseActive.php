<?php

namespace App\Tenant\Http\Middleware;

use App\Central\Models\License;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLicenseActive
{
    /**
     * Handle an incoming request.
     *
     * License enforcement rules:
     * - Suspended: Block ALL access entirely.
     * - Expired:   Allow only GET (read-only mode).
     * - Active:    Allow everything.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if (! $tenant) {
            return $next($request);
        }

        // Fetch the active license from master DB
        $license = License::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->first();

        if (! $license) {
            abort(403, 'No license found for this tenant.');
        }

        // SUSPENDED: Block access entirely
        if ($license->status === 'suspended') {
            abort(403, 'Your account has been suspended. Please contact support.');
        }

        // EXPIRED: Allow only GET requests
        if ($license->status === 'expired' || ($license->expires_at && Carbon::parse($license->expires_at)->isPast())) {
            if (! $request->isMethod('GET')) {
                return response()->json([
                    'message' => 'Your license has expired. Only read-only access is available. Please renew your license.',
                ], 403);
            }
        }

        return $next($request);
    }
}
