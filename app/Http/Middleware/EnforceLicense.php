<?php

namespace App\Http\Middleware;

use App\Support\LicenseRequestGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceLicense
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if (! $tenant || $request->is('api/*/auth/*') || $request->is('api/*/debug-auth') || app()->isLocal() || app()->runningUnitTests()) {
            return $next($request);
        }

        $license = $tenant->license;

        if (! $license) {
            abort(403, 'No license found.');
        }

        if ($license->isExpired()) {
            // Read-only mode: reads are served over POST in this app, so block by
            // read/write intent rather than by HTTP verb — otherwise viewing breaks.
            if (! LicenseRequestGuard::isReadRequest($request)) {
                return response()->json([
                    'message' => 'License expired. Renewal required for write access.',
                    'license_expired' => true,
                    'read_only' => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
