<?php

use App\Models\Staff;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->user, 'sanctum');

    DB::connection('mysql')->table('tenants')->insertOrIgnore([
        'id'            => 'test',
        'name'          => 'Test Tenant',
        'subdomain'     => 'test',
        'database_name' => config('database.connections.mysql.database'),
        'status'        => 'active',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
});

it('returns 404 for missing account', function () {
    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->getJson('/api/v1/tenant/reports/savings-account-statement/999999')
        ->assertStatus(404);
});

it('returns 422 on invalid date params', function () {
    $account = SavingsAccount::factory()->create();

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->getJson("/api/v1/tenant/reports/savings-account-statement/{$account->id}?date_from=not-a-date")
        ->assertStatus(422);
});

it('returns the documented JSON shape', function () {
    $account = SavingsAccount::factory()->create();

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->getJson("/api/v1/tenant/reports/savings-account-statement/{$account->id}")
        ->assertOk()
        ->assertJsonStructure([
            'account'      => ['id', 'account_no', 'account_type', 'product_name'],
            'member'       => ['id', 'name', 'member_number', 'address', 'address_city'],
            'branch'       => ['name'],
            'period'       => ['date_from', 'date_to', 'statement_date'],
            'balances'     => ['opening', 'total_credit', 'total_debit', 'closing', 'count'],
            'transactions',
            'warnings',
        ]);
});
