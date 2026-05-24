<?php

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 1. Find the tenant
$tenant = Tenant::where('subdomain', 'wazalendosacco')->first();

if (!$tenant) {
    echo "Tenant 'wazalendosacco' not found in master database.\n";
    exit(1);
}

echo "Found tenant: " . $tenant->subdomain . " (DB: " . $tenant->database_name . ")\n";

try {
    // 2. Switch to tenant DB
    $switcher = app(DatabaseSwitcher::class);
    $switcher->switch($tenant);
    echo "Switched to database: " . DB::connection('tenant')->getDatabaseName() . "\n";

    // 3. Try to query Staff 1
    $staff = Staff::find(1);
    if ($staff) {
        echo "Found Staff 1: " . $staff->name . "\n";
    } else {
        echo "Staff 1 not found.\n";
    }
    
    // 4. Try to query Expenses
    $expensesCount = DB::connection('tenant')->table('expenses')->count();
    echo "Expenses count: " . $expensesCount . "\n";

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
