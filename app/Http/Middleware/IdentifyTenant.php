<?php

namespace App\Http\Middleware;

use App\Infrastructure\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function __construct(
        protected TenantResolver $resolver
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Resolve the tenant from the subdomain
        $tenant = $this->resolver->resolveFromRequest($request);

        // 2. Bind to container for easy access globally if tenant exists
        if ($tenant) {
            app()->instance('currentTenant', $tenant);

            // Change default guards to tenant for the duration of this request
            config(['auth.defaults.guard' => 'tenant']);
            config(['fortify.guard' => 'tenant']);
        }

        // 3. Optional: Add the tenant to the request attributes for easy access in controllers
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
