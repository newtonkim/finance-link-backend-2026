<?php

namespace App\Http\Middleware;

use App\Infrastructure\Tenancy\DatabaseSwitcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantDatabase
{
    public function __construct(
        protected DatabaseSwitcher $switcher
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        if ($tenant) {
            if (empty($tenant->database_name)) {
                throw new \RuntimeException("Tenant [{$tenant->subdomain}] has no database_name configured.");
            }
            $this->switcher->switch($tenant);
        }

        return $next($request);
    }
}
