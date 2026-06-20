<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Settings\Models\SaccoBranding;
use Illuminate\Support\Str;

class PublicBrandingController extends Controller
{
    /**
     * GET /api/v1/tenant/public-branding
     *
     * Returns the public, non-sensitive branding a tenant login page needs
     * (display name, logo, tagline, accent colour, initials) before the user
     * is authenticated. The tenant DB connection is already selected by the
     * IdentifyTenant/SetTenantDatabase middleware from the X-Tenant-Subdomain
     * header, so no auth is required.
     */
    public function show()
    {
        $tenant = app()->bound('currentTenant') ? app('currentTenant') : null;

        // Read-only lookup (do not create a row from anonymous traffic).
        $branding = SaccoBranding::query()->orderByDesc('id')->first();

        // Authoritative display name comes from the central tenants record,
        // then the tenant's own branding name, then a humanised subdomain.
        $name = $tenant?->name
            ?: ($branding->sacco_name ?? null)
            ?: $this->humaniseSubdomain($tenant?->subdomain);

        $logoUrl = ($branding && $branding->logo_path)
            ? '/storage/'.$branding->logo_path
            : null;

        return response()->json([
            'data' => [
                'name' => $name,
                'subdomain' => $tenant?->subdomain,
                'logo_url' => $logoUrl,
                'tagline' => $branding->tagline ?? null,
                'primary_color' => '#052659',
                'initials' => $this->initials($name),
            ],
        ]);
    }

    /**
     * Turn a glued subdomain into a readable name as a last resort,
     * e.g. "jambosacco" -> "Jambo Sacco".
     */
    private function humaniseSubdomain(?string $subdomain): string
    {
        if (empty($subdomain)) {
            return 'Your SACCO';
        }

        $name = str_replace(['-', '_'], ' ', $subdomain);

        // Split known glued suffixes ("jambosacco" -> "jambo sacco").
        foreach (['sacco', 'cooperative', 'coop'] as $suffix) {
            if (! str_contains($name, ' ') && str_ends_with(strtolower($name), $suffix)) {
                $name = substr($name, 0, -strlen($suffix)).' '.$suffix;
                break;
            }
        }

        return Str::title(trim($name));
    }

    /**
     * Up to two initials from the display name, e.g. "Jambo Sacco" -> "JS".
     */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $words = array_values(array_filter($words));

        if (count($words) >= 2) {
            return strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return strtoupper(mb_substr($name, 0, 2));
    }
}
