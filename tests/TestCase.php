<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\RefreshTenantDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshTenantDatabase;
    private mixed $savedErrorHandler = null;
    private mixed $savedExceptionHandler = null;

    protected function setUp(): void
    {
        // Capture Pest/PHPUnit's handlers before Laravel boots and overrides them
        $this->savedErrorHandler = set_error_handler(function () { return false; });
        restore_error_handler();

        $this->savedExceptionHandler = set_exception_handler(function () { return false; });
        restore_exception_handler();

        $this->refreshApplication();
        $this->redirectToTestDb();

        parent::setUp();

        // After parent::setUp(), RefreshTenantDatabase has started a transaction on 'mysql'.
        // We must sync the transaction level to 'master' and 'tenant' because they share the same PDO.
        $level = \Illuminate\Support\Facades\DB::connection('mysql')->transactionLevel();
        if ($level > 0) {
            foreach (['master', 'tenant'] as $connName) {
                $conn = \Illuminate\Support\Facades\DB::connection($connName);
                $ref = new \ReflectionProperty($conn, 'transactions');
                $ref->setAccessible(true);
                $ref->setValue($conn, $level);
            }
        }
    }

    /**
     * Sync master and tenant connections with the current (test-safe) mysql connection.
     */
    protected function redirectToTestDb(): void
    {
        $mysqlConfig = config('database.connections.mysql');

        // Ensure we are in a test environment - don't run on production DB!
        if (strpos($mysqlConfig['database'], 'test') === false && config('app.env') !== 'testing') {
            throw new \RuntimeException("Tests must run against a 'test' database. Current: ".$mysqlConfig['database']);
        }

        config([
            'database.connections.master' => $mysqlConfig,
            'database.connections.tenant' => $mysqlConfig,
        ]);

        \Illuminate\Support\Facades\DB::purge('master');
        \Illuminate\Support\Facades\DB::purge('tenant');

        // Share PDO for cross-connection transaction visibility
        $pdo = \Illuminate\Support\Facades\DB::connection('mysql')->getPdo();
        \Illuminate\Support\Facades\DB::connection('master')->setPdo($pdo);
        \Illuminate\Support\Facades\DB::connection('tenant')->setPdo($pdo);
    }

    protected function tearDown(): void
    {
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
    }}
