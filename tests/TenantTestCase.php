<?php

namespace Tests;

use App\Central\Models\License;
use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use App\Http\Middleware\SetTenantDatabase;
use App\Tenant\Http\Middleware\EnsureTenantDomain;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Base class for tenant isolation tests.
 *
 * Handles per-test data isolation via transactions and shared PDO.
 */
abstract class TenantTestCase extends BaseTestCase
{
    protected static bool $tenantMigrationsBootstrapped = false;

    private mixed $savedErrorHandler = null;

    private mixed $savedExceptionHandler = null;

    protected function setUp(): void
    {
        // Capture Pest/PHPUnit's handlers before Laravel boots and overrides them
        $this->savedErrorHandler = set_error_handler(function () {
            return false;
        });
        restore_error_handler();

        $this->savedExceptionHandler = set_exception_handler(function () {
            return false;
        });
        restore_exception_handler();

        parent::setUp();

        // TenantTestCase wires the DB connection directly; skip the HTTP
        // middleware that checks/switches tenant databases for real requests.
        $this->withoutMiddleware(EnsureTenantDomain::class);
        $this->withoutMiddleware(SetTenantDatabase::class);

        self::$tenantMigrationsBootstrapped = true;

        // Share one PDO so a single transaction covers both connection names.
        $pdo = DB::connection('mysql')->getPdo();
        DB::connection('tenant')->setPdo($pdo);

        // Fail fast on row-lock contention instead of hanging for long InnoDB waits.
        DB::connection('mysql')->statement('SET SESSION innodb_lock_wait_timeout = 5');
        DB::connection('tenant')->statement('SET SESSION innodb_lock_wait_timeout = 5');

        // Begin the outer test-isolation transaction.
        DB::connection('mysql')->beginTransaction();

        // Mirror the transaction level on the tenant connection object so that
        // subsequent calls to DB::connection('tenant')->transaction() use
        // SAVEPOINTs instead of PDO::beginTransaction(). Both connections share
        // the same PDO, so only one real BEGIN is needed — but the tenant
        // connection object must know it is already inside a transaction.
        $tenantConn = DB::connection('tenant');
        $ref = new \ReflectionProperty($tenantConn, 'transactions');
        $ref->setValue($tenantConn, 1);

        // Seed branches — rolled back after each test.
        DB::connection('mysql')->table('branches')->insertOrIgnore([
            ['id' => 1, 'name' => 'Head Office', 'code' => 'HQ', 'is_active' => 1, 'system_type' => 'system', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ['id' => 2, 'name' => 'Branch Two', 'code' => 'B2', 'is_active' => 1, 'system_type' => 'user_created', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ['id' => 3, 'name' => 'Branch Three', 'code' => 'B3', 'is_active' => 1, 'system_type' => 'user_created', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
        ]);

        // Seed a system staff actor (id=1) so journal entries can reference posted_by=1.
        DB::connection('mysql')->table('staff')->insertOrIgnore([
            ['id' => 1, 'name' => 'System', 'email' => 'system@test.local', 'password' => 'x', 'branch_id' => 1, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
        ]);

        DB::connection('mysql')->table('tenants')->updateOrInsert(
            ['id' => 'test'],
            [
                'name' => 'Test Tenant',
                'subdomain' => 'test',
                'database_name' => config('database.connections.mysql.database'),
                'status' => 'active',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );

        License::query()->where('tenant_id', 'test')->delete();

        $plan = Plan::create([
            'name' => 'Tenant Test Plan '.uniqid(),
            'slug' => 'tenant-test-plan-'.uniqid(),
            'price' => 0,
            'billing_cycle' => 'monthly',
            'features' => [
                'loans' => true,
                'reports' => true,
                'savings' => true,
                'shares' => true,
                'expenses' => true,
                'accounting' => true,
            ],
        ]);

        License::create([
            'tenant_id' => 'test',
            'plan_id' => $plan->id,
            'plan' => (string) $plan->id,
            'starts_at' => Carbon::now()->subMonth()->toDateString(),
            'expires_at' => Carbon::now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        // The HTTP host is localhost in tests, so IdentifyTenant can't resolve the
        // tenant from the subdomain. Bind it explicitly so the license/feature
        // middleware guarding tenant routes has its context in every tenant test.
        app()->instance('currentTenant', Tenant::where('subdomain', 'test')->first());
    }

    protected function tearDown(): void
    {
        try {
            if (DB::connection('mysql')->transactionLevel() > 0) {
                DB::connection('mysql')->rollBack();
            }
        } catch (\Throwable) {
            // Connection may have been reset by app teardown — ignore.
        }

        // Decouple shared PDO and reset transaction level spoofing
        $tenantConn = DB::connection('tenant');
        $tenantConn->setPdo(null);
        $ref = new \ReflectionProperty($tenantConn, 'transactions');
        $ref->setValue($tenantConn, 0);

        parent::tearDown();

        // Laravel's teardown flushes all handlers. We must manually push
        // Pest/PHPUnit's original handlers back on the stack.
        if ($this->savedErrorHandler !== null) {
            set_error_handler($this->savedErrorHandler);
            $this->savedErrorHandler = null;
        }

        if ($this->savedExceptionHandler !== null) {
            set_exception_handler($this->savedExceptionHandler);
            $this->savedExceptionHandler = null;
        }
    }
}
