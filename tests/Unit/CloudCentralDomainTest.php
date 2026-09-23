<?php

namespace Tests\Unit;

use App\Central\Http\Middleware\EnsureCentralDomain;
use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\TenantResolver;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CloudCentralDomainTest extends TestCase
{
    private const HOST = 'finance-link-backend-2026-production-n5gk0u.laravel.cloud';

    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Application;
        $container->instance('config', new Repository([
            'app' => require __DIR__.'/../../config/app.php',
        ]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_central_registration_does_not_look_up_the_cloud_hostname_as_a_tenant(): void
    {
        $request = Request::create('https://'.self::HOST.'/api/v1/auth/register', 'POST');
        self::assertNull((new TenantResolver)->resolveFromRequest($request));
    }

    public function test_cloud_host_passes_the_central_domain_check(): void
    {
        $request = Request::create('https://'.self::HOST.'/api/v1/central/me');
        $response = (new EnsureCentralDomain)->handle($request, fn () => new Response('central'));
        self::assertSame('central', $response->getContent());
    }

    public function test_forwarded_cloud_host_is_also_central(): void
    {
        $request = Request::create('http://internal/api/v1/auth/login', 'POST', server: [
            'HTTP_X_FORWARDED_HOST' => self::HOST.':443',
        ]);
        self::assertNull((new TenantResolver)->resolveFromRequest($request));
        $response = (new EnsureCentralDomain)->handle($request, fn () => new Response('central'));
        self::assertSame('central', $response->getContent());
    }

    public function test_tenant_header_still_requires_a_valid_active_tenant_on_the_shared_host(): void
    {
        // Mock only the database boundary; a missing tenant must still fail.
        $connection = Mockery::mock(Connection::class)->makePartial();
        $connection->shouldReceive('getQueryGrammar')->andReturn(new Grammar($connection));
        $connection->shouldReceive('getPostProcessor')->andReturn(new Processor);
        $connection->shouldReceive('select')->once()->andReturn([]);
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);
        $previous = Tenant::getConnectionResolver();
        Tenant::setConnectionResolver($resolver);
        try {
            $request = Request::create('https://'.self::HOST.'/api/v1/tenant/auth/login', 'POST', server: [
                'HTTP_X_TENANT_SUBDOMAIN' => 'missing-demo',
            ]);
            $this->expectException(NotFoundHttpException::class);
            $this->expectExceptionMessage('Tenant [missing-demo] not found or inactive.');
            (new TenantResolver)->resolveFromRequest($request);
        } finally {
            if ($previous) {
                Tenant::setConnectionResolver($previous);
            } else {
                Tenant::unsetConnectionResolver();
            }
        }
    }
}
