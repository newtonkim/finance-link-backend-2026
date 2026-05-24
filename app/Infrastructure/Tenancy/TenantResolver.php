<?php

namespace App\Infrastructure\Tenancy;

use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TenantResolver
{
    /**
     * Resolve the tenant from the incoming request.
     *
     * @throws NotFoundHttpException
     */
    public function resolveFromRequest(Request $request): ?Tenant
    {
        $host = $request->getHost();

        // 1. Determine the subdomain (Header takes precedence)
        $subdomain = $request->header('X-Tenant-Subdomain');

        if (empty($subdomain)) {
            // 2. Fall back to host-based subdomain extraction
            $forwardedHost = $request->headers->get('x-forwarded-host');
            $host = $forwardedHost
                ? trim(explode(',', $forwardedHost)[0])
                : $request->getHost();

            // Strip port if present
            if (str_contains($host, ':')) {
                $host = explode(':', $host)[0];
            }

            if (in_array($host, config('app.central_domains')) || $host === 'localhost' || $host === '127.0.0.1') {
                return null;
            }

            $subdomain = explode('.', $host)[0];
        }

        // 3. Ignore common non-tenant subdomains
        if (empty($subdomain) || in_array(strtolower($subdomain), ['api', 'www', 'admin', 'central', 'localhost'])) {
            return null;
        }

        // 4. Look up the tenant
        $tenant = Tenant::where('subdomain', $subdomain)
            ->where('status', 'active')
            ->first();

        if (! $tenant) {
            throw new NotFoundHttpException("Tenant [{$subdomain}] not found or inactive.");
        }

        return $tenant;
    }
}
