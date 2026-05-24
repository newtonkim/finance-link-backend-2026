<?php

namespace App\Central\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureCentralDomain
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $centralDomains = config('app.central_domains');
        $forwardedHost = $request->headers->get('x-forwarded-host');
        $host = $forwardedHost
            ? trim(explode(',', $forwardedHost)[0])
            : $request->getHost();

        // Strip port if present
        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        if ($this->isAuthorizedCentralHost($host, $centralDomains)) {
            return $next($request);
        }

        Log::warning("Unauthorized central domain access attempt: {$host}");
        abort(403, 'This domain is not authorized to access the central admin panel.');
    }

    /**
     * Check if the host is an authorized central domain.
     * Accepts exact matches AND api.* prefixed versions of central domains
     * (e.g. api.staging.mfukoplus.com is valid when staging.mfukoplus.com is authorized).
     */
    protected function isAuthorizedCentralHost(string $host, array $centralDomains): bool
    {
        // Always allow localhost / 127.0.0.1
        if ($host === 'localhost' || $host === '127.0.0.1') {
            return true;
        }

        // Exact match
        if (in_array($host, $centralDomains)) {
            return true;
        }

        // Allow api.{central_domain} — the frontend SPA calls the API on this subdomain
        $hostWithoutApiPrefix = preg_replace('/^api\./', '', $host);
        if ($hostWithoutApiPrefix !== $host && in_array($hostWithoutApiPrefix, $centralDomains)) {
            return true;
        }

        return false;
    }
}
