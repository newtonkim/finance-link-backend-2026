<?php

namespace App\Tenant\Http\Middleware;

use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Infrastructure\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantDomain
{
    public function __construct(
        protected TenantResolver $resolver,
        protected DatabaseSwitcher $switcher
    ) {}

    /**
     * Handle an incoming request.
     * Validates the request is for a valid tenant subdomain and switches to its DB.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Tenant routes always require an X-Tenant-Subdomain header.
        // Without it the tenant connection has no database selected, which causes
        // Sanctum's auth guard to fail when resolving Staff models.
        if (! $request->hasHeader('X-Tenant-Subdomain')) {
            abort(400, 'Tenant subdomain header (X-Tenant-Subdomain) is required for tenant API routes.');
        }

        // Resolve the tenant from the subdomain
        $tenant = $this->resolver->resolveFromRequest($request);

        if (! $tenant) {
            abort(404, 'Tenant not found.');
        }

        // Bind the tenant to the container
        app()->instance('currentTenant', $tenant);
        $request->attributes->set('tenant', $tenant);

        // Switch to tenant database
        $this->switcher->switch($tenant);

        return $next($request);
    }
}
