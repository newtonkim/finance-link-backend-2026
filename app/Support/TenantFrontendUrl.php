<?php

namespace App\Support;

class TenantFrontendUrl
{
    public static function base(string $subdomain, string $frontendUrl, ?string $customDomain = null): string
    {
        $parsed = parse_url($frontendUrl);
        $scheme = $parsed['scheme'] ?? 'http';
        $host = strtolower($parsed['host'] ?? 'localhost');
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        if ($customDomain) {
            return $scheme.'://'.$customDomain.$port;
        }

        // Vercel project domains cannot serve arbitrary nested tenant hosts.
        // Each <tenant>.vercel.app alias must be assigned to the project first.
        if (str_ends_with($host, '.vercel.app') || $host === 'vercel.app') {
            return 'https://'.$subdomain.'.vercel.app';
        }

        if (str_starts_with($host, 'api.')) {
            $host = substr($host, 4);
        }
        if ($host === '127.0.0.1') {
            $host = 'localhost';
        }

        return $scheme.'://'.$subdomain.'.'.$host.$port;
    }
}
