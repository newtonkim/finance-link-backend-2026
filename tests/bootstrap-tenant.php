<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Bootstrap script for tenant tests.
 * Migrates the tenant schema into the test database once before any test runs.
 * Referenced from phpunit.xml as the Tenant suite bootstrap.
 *
 * The tenant migrations use Schema::connection('tenant') for DDL, so we must
 * configure the tenant connection to point at the test DB before running them.
 */

require_once __DIR__.'/../vendor/autoload.php';

if (! defined('PHPUNIT_COMPOSER_INSTALL')) {
    define('PHPUNIT_COMPOSER_INSTALL', __DIR__.'/../vendor/autoload.php');
}

$app = require_once __DIR__.'/../bootstrap/app.php';

/** @var Kernel $kernel */
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$testDb = env('DB_DATABASE', 'mfukopro_test');

// Point the tenant, master, and default (mysql) connections at the test DB.
$mysqlConf = config('database.connections.mysql');
$mysqlConf['database'] = $testDb;

config([
    'database.connections.mysql' => $mysqlConf,
    'database.connections.tenant' => $mysqlConf,
    'database.connections.master' => $mysqlConf,
]);

DB::purge('mysql');
DB::purge('tenant');
DB::purge('master');

// Ensure the test database exists before we try to use it.
$host = config('database.connections.mysql.host');
$port = config('database.connections.mysql.port');
$user = config('database.connections.mysql.username');
$pass = config('database.connections.mysql.password');

try {
    $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$testDb}`");
} catch (Throwable $e) {
    fwrite(STDERR, "[tenant bootstrap] Warning: Could not ensure test database exists: {$e->getMessage()}\n");
}

// Kill any sleeping connections that hold open InnoDB transactions.
try {
    $zombies = DB::connection('tenant')->select(
        "SELECT p.id FROM information_schema.processlist p
         JOIN information_schema.innodb_trx t ON t.trx_mysql_thread_id = p.id
         WHERE p.command = 'Sleep' AND p.db = ? AND p.id != CONNECTION_ID()",
        [$testDb]
    );
    foreach ($zombies as $zombie) {
        try {
            DB::connection('tenant')->unprepared("KILL {$zombie->id}");
        } catch (Throwable) {
            // Already gone — safe to ignore.
        }
    }
} catch (Throwable) {
    // information_schema query failed — skip silently.
}

// Increase memory limit for large migration sets.
ini_set('memory_limit', '1024M');

$lockDir = storage_path('framework/testing');
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0777, true);
}
$lockFile = $lockDir . '/tenant_bootstrap.lock';
$lockFp = fopen($lockFile, 'w+');

if (!$lockFp) {
    fwrite(STDERR, "[tenant bootstrap] Fatal: Could not open lock file {$lockFile}\n");
    exit(1);
}

// Wait for the lock — only one process can migrate at a time.
if (!flock($lockFp, LOCK_EX)) {
    fwrite(STDERR, "[tenant bootstrap] Fatal: Could not acquire lock on {$lockFile}\n");
    exit(1);
}

/**
 * Run tenant migrations, with automatic retry on stale schema.
 */
$migrationAttempts = 0;
$maxAttempts = 2;

while ($migrationAttempts < $maxAttempts) {
    $migrationAttempts++;

    try {

        $exitCode = $kernel->call('migrate', [
            '--path' => [
                'database/migrations',
                'database/migrations/landlord',
                'database/migrations/tenant',
            ],
            '--database' => 'tenant',
            '--force' => true,
        ]);

        if ($exitCode !== 0) {
            $output = $kernel->output();
            throw new Exception("Migration command failed with exit code {$exitCode}. Output: {$output}");
        }

        // Migrations succeeded — break out of the retry loop.
        break;

    } catch (Throwable $e) {
        fwrite(STDERR, "[tenant bootstrap] Migration failed: " . $e->getMessage() . "\n");

        if ($migrationAttempts >= $maxAttempts) {
            fwrite(STDERR, "[tenant bootstrap] Migration failed after {$maxAttempts} attempts. Failing.\n");
            flock($lockFp, LOCK_UN);
            throw $e;
        }

        // ONLY reset the database if we hit a 'hard' schema error that implies
        // the migrations table is out of sync with the actual table structure.
        // We MUST NOT drop the DB for lock timeouts, as another process's tests
        // might be holding locks while this process tries to migrate.
        $message = $e->getMessage();
        // MySQL prints the table name between the code and "already exists":
        //   "1050 Table 'jobs' already exists"
        // so a substring search for "1050 Table already exists" never matches.
        // Use a regex (or just the code prefixes) so the retry actually fires.
        $isCorruptionError = preg_match('/\b(1050|1060|1061|1054|1091)\b/', $message) === 1;

        if (!$isCorruptionError) {
            fwrite(STDERR, "[tenant bootstrap] Transient error detected. Failing without resetting database.\n");
            flock($lockFp, LOCK_UN);
            throw $e;
        }

        fwrite(STDERR, "[tenant bootstrap] Resetting test database '{$testDb}' and retrying...\n");

        try {
            // Re-bootstrap connections to be absolutely sure.
            DB::purge('mysql');
            DB::purge('tenant');
            DB::purge('master');

            $pdo = new PDO("mysql:host={$host};port={$port}", $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Kill ALL connections to the test DB to ensure we can drop it.
            try {
                $stmt = $pdo->query("SELECT id FROM information_schema.processlist WHERE db = '{$testDb}' AND id != CONNECTION_ID()");
                if ($stmt) {
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $pdo->exec("KILL {$row['Id']}");
                    }
                }
            } catch (Throwable) {
                // Ignore errors during kill.
            }

            // Force a clean database by dropping and recreating without IF NOT EXISTS
            $pdo->exec("DROP DATABASE IF EXISTS `{$testDb}`");
            $pdo->exec("CREATE DATABASE `{$testDb}`");
            $pdo = null;

            DB::purge('mysql');
            DB::purge('tenant');
            DB::purge('master');
        } catch (Throwable $resetError) {
            fwrite(STDERR, "[tenant bootstrap] Could not reset test database: {$resetError->getMessage()}\n");
            flock($lockFp, LOCK_UN);
            break;
        }
    }
}

// Release the lock
flock($lockFp, LOCK_UN);
fclose($lockFp);
