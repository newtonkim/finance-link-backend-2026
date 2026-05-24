<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$tenantId = (string) Str::uuid();
$licenseId = (string) Str::uuid();

try {
    DB::connection('master')->transaction(function () use ($tenantId, $licenseId) {
        // Insert Tenant
        DB::connection('master')->table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Wazalendo Sacco',
            'subdomain' => 'wazalendosacco',
            'domain' => null,
            'database_name' => 'sacco_wazalendosacco',
            'status' => 'active',
            'settings' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'system_type' => 'user_created'
        ]);

        // Insert License
        DB::connection('master')->table('licenses')->insert([
            'id' => $licenseId,
            'tenant_id' => $tenantId,
            'plan' => 2,
            'starts_at' => now()->format('Y-m-d'),
            'expires_at' => now()->addDays(30)->format('Y-m-d'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
            'system_type' => 'user_created'
        ]);
    });

    echo "Wazalendo Sacco successfully registered with ID: $tenantId\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
