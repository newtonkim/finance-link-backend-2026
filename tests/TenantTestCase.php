<?php

namespace Tests;

use App\Tenant\Http\Middleware\EnsureTenantDomain;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $this->savedErrorHandler = set_error_handler(function () { return false; });
        restore_error_handler();

        $this->savedExceptionHandler = set_exception_handler(function () { return false; });
        restore_exception_handler();

        parent::setUp();

        // TenantTestCase wires the DB connection directly; skip the HTTP
        // middleware that checks for X-Tenant-Subdomain header.
        $this->withoutMiddleware(EnsureTenantDomain::class);

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
            ['id' => 1, 'name' => 'Head Office', 'code' => 'HQ', 'is_active' => 1, 'system_type' => 'system', 'created_at' => \Illuminate\Support\Carbon::now(), 'updated_at' => \Illuminate\Support\Carbon::now()],
            ['id' => 2, 'name' => 'Branch Two', 'code' => 'B2', 'is_active' => 1, 'system_type' => 'user_created', 'created_at' => \Illuminate\Support\Carbon::now(), 'updated_at' => \Illuminate\Support\Carbon::now()],
            ['id' => 3, 'name' => 'Branch Three', 'code' => 'B3', 'is_active' => 1, 'system_type' => 'user_created', 'created_at' => \Illuminate\Support\Carbon::now(), 'updated_at' => \Illuminate\Support\Carbon::now()],
        ]);

        // Seed a system staff actor (id=1) so journal entries can reference posted_by=1.
        DB::connection('mysql')->table('staff')->insertOrIgnore([
            ['id' => 1, 'name' => 'System', 'email' => 'system@test.local', 'password' => 'x', 'branch_id' => 1, 'created_at' => \Illuminate\Support\Carbon::now(), 'updated_at' => \Illuminate\Support\Carbon::now()],
        ]);
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
