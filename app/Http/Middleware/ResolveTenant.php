<?php

namespace App\Http\Middleware;

use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Infrastructure\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(
        protected TenantResolver $resolver,
        protected DatabaseSwitcher $switcher
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Skip resolution if central domain
        $forwardedHost = $request->headers->get('x-forwarded-host');
        $host = $forwardedHost
            ? trim(explode(',', $forwardedHost)[0])
            : $request->getHost();

        // Strip port if present
        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        if (in_array($host, config('app.central_domains')) || $host === 'localhost' || $host === '127.0.0.1') {
            return $next($request);
        }

        // 2. Resolve the tenant from the subdomain
        $tenant = $this->resolver->resolveFromRequest($request);

        // 2. Switch the database connection to the tenant's database
        $this->switcher->switch($tenant);

        // 3. Optional: Add the tenant to the request attributes for easy access in controllers
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }
}
