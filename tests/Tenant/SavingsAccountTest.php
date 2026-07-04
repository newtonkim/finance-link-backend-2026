<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Transactions\Models\Transaction;

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

it('rejects deposits when deposit charges equal or exceed the deposit amount', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $account = SavingsAccount::factory()->create([
        'member_id' => $member->id,
        'balance' => 1000,
        'selected_charges' => [
            [
                'type' => 'deposit',
                'name' => 'Large Deposit Fee',
                'charge_type' => 'amount',
                'amount' => 500,
                'minimum_amount' => 0,
                'maximum_amount' => 0,
                'is_reversible' => true,
            ],
        ],
    ]);

    $this->postJson("http://test.mfukopro.test/api/v1/tenant/savings-accounts/{$account->id}/deposit", [
        'deposit_date' => now()->format('Y-m-d'),
        'amount' => 500,
        'payment_mode' => 'cash',
        'narration' => 'Bad Deposit',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');

    expect((float) $account->fresh()->balance)->toBe(1000.00);
    expect(Transaction::where('account_id', $account->id)->count())->toBe(0);
});

it('preserves configured GL account on deposit charge transactions', function () {
    $incomeGl = ChartOfAccount::create([
        'gl_code' => '41999',
        'name' => 'Deposit Charge Income',
        'account_type' => 'INCOME',
        'account_subtype' => 'Fee Income',
        'normal_balance' => 'CR',
        'level' => 3,
        'is_control' => false,
        'is_postable' => true,
        'is_active' => true,
    ]);
    $member = Member::factory()->create(['status' => 'active']);
    $account = SavingsAccount::factory()->create([
        'member_id' => $member->id,
        'balance' => 1000,
        'selected_charges' => [
            [
                'type' => 'deposit',
                'name' => 'Deposit Fee',
                'charge_type' => 'amount',
                'amount' => 50,
                'minimum_amount' => 0,
                'maximum_amount' => 0,
                'credit_account_id' => $incomeGl->id,
                'is_reversible' => true,
            ],
        ],
    ]);

    $this->postJson("http://test.mfukopro.test/api/v1/tenant/savings-accounts/{$account->id}/deposit", [
        'deposit_date' => now()->format('Y-m-d'),
        'amount' => 500,
        'payment_mode' => 'cash',
        'narration' => 'Deposit With Fee',
    ])->assertStatus(200);

    $chargeTransaction = Transaction::where('account_id', $account->id)
        ->where('type', 'charge')
        ->firstOrFail();

    expect((float) $account->fresh()->balance)->toBe(1450.00);
    expect($chargeTransaction->gl_credit_account_id)->toBe($incomeGl->id);
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
