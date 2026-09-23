<?php

namespace App\Http\Middleware;

use App\Tenant\Http\Middleware\EnsureLicenseActive;
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

        // Use the same master-database lookup and policy as tenant API routes.
        // Tenant has licenses(), not a singular license relationship.
        return app(EnsureLicenseActive::class)->handle($request, $next);
    }
}
