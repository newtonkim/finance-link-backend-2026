<?php

namespace Tests\Unit;

use Fruitcake\Cors\CorsService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CorsPreflightTest extends TestCase
{
    public static function origins(): array
    {
        return [
            ['https://finance-link-frontend-2026.vercel.app', true],
            ['https://mfukodemo.vercel.app', true],
            ['https://buwatesacco.vercel.app', true],
            ['https://finance-link-frontend-2026-git-main-newtonyamu22-2109s-projects.vercel.app', true],
            ['https://unrelated.vercel.app', false],
            ['https://mfukodemo.vercel.app.attacker.example', false],
        ];
    }

    #[DataProvider('origins')]
    public function test_tenant_login_preflight(string $origin, bool $allowed): void
    {
        $container = new Container;
        $container->instance('config', new Repository([
            'cors' => require __DIR__.'/../../config/cors.php',
        ]));
        $middleware = new HandleCors($container, new CorsService);
        $request = Request::create('/api/v1/tenant/auth/login', 'OPTIONS', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-tenant-subdomain,authorization',
        ]);
        $response = $middleware->handle($request, function () {
            self::fail('Preflight must finish before tenant authentication.');
        });

        self::assertSame(204, $response->getStatusCode());
        self::assertSame($allowed ? $origin : null, $response->headers->get('Access-Control-Allow-Origin'));
        if ($allowed) {
            self::assertStringContainsString('POST', $response->headers->get('Access-Control-Allow-Methods'));
            self::assertStringContainsString('x-tenant-subdomain', $response->headers->get('Access-Control-Allow-Headers'));
        }
    }
}
