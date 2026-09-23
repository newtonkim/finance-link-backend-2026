<?php

namespace Tests\Unit;

use App\Support\TenantFrontendUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TenantFrontendUrlTest extends TestCase
{
    public static function urls(): array
    {
        return [
            ['https://finance-link-frontend-2026.vercel.app', null, 'https://buwatesacco.vercel.app'],
            ['https://finance-link-frontend-2026-git-main-team.vercel.app/central/tenants', null, 'https://buwatesacco.vercel.app'],
            ['https://finance-link-frontend-2026.vercel.app', 'sacco.example.com', 'https://sacco.example.com'],
            ['http://localhost:3000', null, 'http://buwatesacco.localhost:3000'],
            ['http://127.0.0.1:3000', null, 'http://buwatesacco.localhost:3000'],
            ['https://api.staging.mfukoplus.com', null, 'https://buwatesacco.staging.mfukoplus.com'],
            ['https://mfukopro.ug', null, 'https://buwatesacco.mfukopro.ug'],
        ];
    }

    #[DataProvider('urls')]
    public function test_tenant_frontend_url(string $frontend, ?string $custom, string $expected): void
    {
        self::assertSame($expected, TenantFrontendUrl::base('buwatesacco', $frontend, $custom));
    }
}
