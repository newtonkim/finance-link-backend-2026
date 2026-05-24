<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$app = require_once dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Domain\Tenancy\Entities\Tenant;

$tenant = Tenant::where('subdomain', 'wazalendosacco')->first();
if (!$tenant) {
    echo "Tenant not found\n";
    exit(1);
}

app(\App\Infrastructure\Tenancy\DatabaseSwitcher::class)->switch($tenant);

$hasColumn = Schema::connection('tenant')->hasColumn('staff', 'avatar');
echo "Column 'avatar' in 'staff' table: " . ($hasColumn ? 'YES' : 'NO') . "\n";

if (!$hasColumn) {
    echo "Attempting to run migration manually...\n";
    \Illuminate\Support\Facades\Artisan::call('migrate', [
        '--database' => 'tenant',
        '--path' => 'database/migrations/tenant',
        '--force' => true,
    ]);
    echo \Illuminate\Support\Facades\Artisan::output();
}
