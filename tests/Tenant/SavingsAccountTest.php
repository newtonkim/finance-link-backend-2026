<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;

beforeEach(function () {
    $this->staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->staff, 'sanctum');
});

it('can list savings accounts', function () {
    SavingsAccount::factory()->count(2)->create();

    $this->getJson('http://test.mfukopro.test/api/v1/tenant/savings-accounts')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

it('can create a savings account', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $product = SavingsProduct::factory()->create(['minimum_balance' => 0]);

    $this->postJson('http://test.mfukopro.test/api/v1/tenant/savings-accounts', [
        'member_id' => $member->id,
        'savings_product_id' => $product->id,
        'account_type' => 'standard',
        'initial_deposit' => 1000,
    ])->assertStatus(201);

    $this->assertDatabaseHas('savings_accounts', [
        'member_id' => $member->id,
        'balance' => 1000,
    ], 'tenant');
});

it('can deposit to a savings account', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $account = SavingsAccount::factory()->create(['member_id' => $member->id, 'balance' => 1000]);

    $this->postJson("http://test.mfukopro.test/api/v1/tenant/savings-accounts/{$account->id}/deposit", [
        'deposit_date' => now()->format('Y-m-d'),
        'amount' => 500,
        'payment_mode' => 'cash',
        'narration' => 'Test Deposit',
    ])->assertStatus(200);

    expect((float) $account->fresh()->balance)->toBe(1500.00);
});

it('can withdraw from a savings account', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $account = SavingsAccount::factory()->create([
        'member_id' => $member->id,
        'balance' => 1000,
        'consider_min_balance' => false,
    ]);

    $this->postJson("http://test.mfukopro.test/api/v1/tenant/savings-accounts/{$account->id}/withdraw", [
        'deposit_date' => now()->format('Y-m-d'),
        'amount' => 400,
        'payment_mode' => 'cash',
    ])->assertStatus(200);

    expect((float) $account->fresh()->balance)->toBe(600.00);
});
