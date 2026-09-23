<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Entities\Tenant;
use App\Http\Middleware\EnforceLicense;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EnforceLicenseTest extends TestCase
{
    private Manager $db;

    private Container $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $app = new Application;
        $app->instance('env', 'production');
        Container::setInstance($app);
        $this->db = new Manager($app);
        foreach (['master', 'tenant'] as $name) {
            $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], $name);
            $this->db->getConnection($name)->getSchemaBuilder()->create('licenses', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('tenant_id');
                $table->string('status');
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
        $this->db->bootEloquent();
        $this->db->getDatabaseManager()->setDefaultConnection('tenant');
        $tenant = new Tenant;
        $tenant->id = 'buwate';
        $app->instance('currentTenant', $tenant);
        $factory = Mockery::mock(ResponseFactory::class);
        $factory->shouldReceive('json')->andReturnUsing(fn ($data, $status) => new JsonResponse($data, $status));
        $app->instance(ResponseFactory::class, $factory);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    private function license(string $status, string $expiry): void
    {
        $this->db->getConnection('master')->table('licenses')->insert([
            'id' => 'license', 'tenant_id' => 'buwate', 'status' => $status,
            'expires_at' => $expiry, 'created_at' => '2026-01-01',
        ]);
    }

    private function request(string $method = 'GET'): Response
    {
        return (new EnforceLicense)->handle(
            Request::create('/api/v1/tenant/chart-of-accounts', $method),
            fn () => new Response('accounts'),
        );
    }

    public function test_active_master_license_allows_accounts_after_switching_to_tenant_database(): void
    {
        $this->license('active', '2099-01-01');
        self::assertSame('accounts', $this->request()->getContent());
    }

    public function test_missing_license_remains_blocked(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('No license found for this tenant.');
        $this->request();
    }

    public function test_suspended_license_remains_blocked(): void
    {
        $this->license('suspended', '2099-01-01');
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('suspended');
        $this->request();
    }

    public function test_expired_license_allows_reads_but_blocks_writes(): void
    {
        $this->license('expired', '2000-01-01');
        self::assertSame(200, $this->request()->getStatusCode());
        self::assertSame(403, $this->request('PUT')->getStatusCode());
    }
}
