<?php

namespace App\Http\Middleware;

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
            // Block all mutative operations if license is expired
            if (in_array($request->method(), ['POST', 'PUT', 'DELETE', 'PATCH'])) {
                return response()->json([
                    'message' => 'License expired. Renewal required for write access.',
                ], 403);
            }
        }

        return $next($request);
    }
}
