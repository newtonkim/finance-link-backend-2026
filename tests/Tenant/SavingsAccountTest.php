<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->user, 'sanctum');
    
    DB::connection('mysql')->table('tenants')->insertOrIgnore([
        'id' => 'test',
        'name' => 'Test Tenant',
        'subdomain' => 'test',
        'database_name' => config('database.connections.mysql.database'),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('can list savings accounts', function () {
    SavingsAccount::factory()->count(2)->create();

    $response = $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->getJson('/api/v1/tenant/savings-accounts');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

it('can create a savings account', function () {
    $member = Member::factory()->create();
    $product = SavingsProduct::factory()->create(['minimum_balance' => 0]);

    $data = [
        'member_id' => $member->id,
        'savings_product_id' => $product->id,
        'account_type' => 'standard',
        'initial_deposit' => 1000,
    ];

    $response = $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson('/api/v1/tenant/savings-accounts', $data);

    $response->assertStatus(201);
    $this->assertDatabaseHas('savings_accounts', [
        'member_id' => $member->id,
        'balance' => 1000
    ], 'tenant');
});

it('can deposit to a savings account', function () {
    $account = SavingsAccount::factory()->create(['balance' => 1000]);

    $data = [
        'deposit_date' => now()->format('Y-m-d'),
        'amount' => 500,
        'payment_mode' => 'cash',
        'narration' => 'Test Deposit',
    ];

    $response = $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson("/api/v1/tenant/savings-accounts/{$account->id}/deposit", $data);

    $response->assertStatus(200);
    // Note: use assertEquals for float comparison if needed, but toBe(1500.00) usually works
    expect((float)$account->fresh()->balance)->toBe(1500.00);
});

it('can withdraw from a savings account', function () {
    $account = SavingsAccount::factory()->create([
        'balance' => 1000,
        'consider_min_balance' => false
    ]);

    $data = [
        'deposit_date' => now()->format('Y-m-d'),
        'amount' => 400,
        'payment_mode' => 'cash',
    ];

    $response = $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson("/api/v1/tenant/savings-accounts/{$account->id}/withdraw", $data);

    $response->assertStatus(200);
    expect((float)$account->fresh()->balance)->toBe(600.00);
});
