<?php

namespace App\Tenant\Http\Middleware;

use App\Central\Models\License;
use App\Support\LicenseRequestGuard;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLicenseActive
{
    /**
     * License enforcement rules:
     * - Suspended: block ALL access.
     * - Expired:   read-only — allow reads (any verb, incl. POST list endpoints),
     *              block every create/update/delete.
     * - Active:    allow everything.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if (! $tenant) {
            return $next($request);
        }

        $license = License::where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->first();

        if (! $license) {
            abort(403, 'No license found for this tenant.');
        }

        // SUSPENDED: block access entirely.
        if ($license->status === 'suspended') {
            abort(403, 'Your account has been suspended. Please contact support.');
        }

        // EXPIRED: read-only mode — reads pass, mutations are blocked.
        $isExpired = $license->status === 'expired'
            || ($license->expires_at && Carbon::parse($license->expires_at)->isPast());

        if ($isExpired && ! LicenseRequestGuard::isReadRequest($request)) {
            return response()->json([
                'message' => 'Your license has expired. You can still view your data, but creating, '
                    .'updating and deleting are disabled until you renew.',
                'license_expired' => true,
                'read_only' => true,
            ], 403);
        }

        return $next($request);
    }
}
