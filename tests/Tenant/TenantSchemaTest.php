<?php

use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Support\Facades\Schema;

it('has all required tenant tables', function () {
    $requiredTables = [
        'staff',
        'members',
        'savings_accounts',
        'savings_products',
        'loans',
        'transactions',
        'chart_of_accounts',
    ];

    foreach ($requiredTables as $table) {
        expect(Schema::connection('mysql')->hasTable($table))
            ->toBeTrue("Table '{$table}' is missing from tenant migrations.");
    }
});

it('executes tenants:migrate command successfully', function () {
    // Create a mock tenant
    Tenant::create([
        'id' => 'schema-test',
        'name' => 'Schema Test Sacco',
        'subdomain' => 'schema-test',
        'database_name' => config('database.connections.mysql.database'),
        'status' => 'active',
    ]);

    $this->artisan('tenants:migrate')
        ->assertExitCode(0)
        ->expectsOutputToContain('Completed migration for: Schema Test Sacco');
});
