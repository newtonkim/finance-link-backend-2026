<?php

use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->staff = Staff::create([
        'name' => 'Tenant Admin',
        'email' => 'admin@gap4-test.com',
        'password' => Hash::make('password'),
        'role' => 'Admin',
        'is_tenant_admin' => true,
    ]);

    // A real INCOME COA row the request can target as credit_account_id.
    $this->income = ChartOfAccount::create([
        'gl_code' => '42999',
        'name' => 'Test Charge Income',
        'account_type' => 'INCOME',
        'account_subtype' => 'Fee Income',
        'normal_balance' => 'CR',
        'level' => 3,
        'is_control' => false,
        'is_postable' => true,
        'is_active' => true,
    ]);

    $this->productA = SavingsProduct::factory()->create();
    $this->productB = SavingsProduct::factory()->create();
});

it('creates pivot rows on store: products × trigger_types cross-product', function () {
    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/general-charges', [
            'name' => 'Monthly Maintenance Fee',
            'is_revenue' => 'yes',
            'application' => 'other',
            'where_to_apply' => 'savings',
            'charge_type' => 'amount',
            'amount' => 500,
            'credit_account_id' => $this->income->id,
            'saving_product_ids' => [$this->productA->id, $this->productB->id],
            'trigger_types' => ['deposit', 'withdraw'],
            'is_reversible' => true,
        ]);

    $response->assertCreated();

    $charge = GeneralCharge::query()->where('name', 'Monthly Maintenance Fee')->firstOrFail();

    // 2 products × 2 trigger types = 4 pivot rows
    $pivot = SavingsProductCharge::query()->where('general_charge_id', $charge->id)->get();
    expect($pivot)->toHaveCount(4);

    $pairs = $pivot->map(fn ($r) => $r->savings_product_id.'/'.$r->type)->sort()->values()->all();
    expect($pairs)->toEqual([
        "{$this->productA->id}/deposit",
        "{$this->productA->id}/withdraw",
        "{$this->productB->id}/deposit",
        "{$this->productB->id}/withdraw",
    ]);
});

it('rewrites pivot rows on update: removes old, inserts new', function () {
    // Seed an existing charge with one pivot row (productA, deposit)
    $charge = GeneralCharge::factory()->create(['credit_account_id' => $this->income->id]);
    SavingsProductCharge::query()->insert([
        'savings_product_id' => $this->productA->id,
        'general_charge_id' => $charge->id,
        'type' => 'deposit',
        'name' => $charge->name,
        'charge_type' => 'amount',
        'amount' => 100,
        'minimum_amount' => 0,
        'is_reversible' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Update: switch to productB only, on transfer only
    $response = $this->actingAs($this->staff, 'tenant')
        ->putJson("/api/v1/tenant/general-charges/{$charge->id}", [
            'name' => $charge->name,
            'is_revenue' => 'yes',
            'application' => 'other',
            'where_to_apply' => 'savings',
            'charge_type' => 'amount',
            'amount' => $charge->amount,
            'credit_account_id' => $this->income->id,
            'saving_product_ids' => [$this->productB->id],
            'trigger_types' => ['transfer'],
        ]);

    $response->assertOk();

    $pivot = SavingsProductCharge::query()->where('general_charge_id', $charge->id)->get();
    expect($pivot)->toHaveCount(1);
    expect($pivot->first()->savings_product_id)->toBe($this->productB->id);
    expect($pivot->first()->type)->toBe('transfer');
});

it('skips pivot creation when either saving_product_ids or trigger_types is empty', function () {
    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/general-charges', [
            'name' => 'Registration Charge',
            'is_revenue' => 'yes',
            'application' => 'on_registration',
            'charge_type' => 'amount',
            'amount' => 2500,
            'credit_account_id' => $this->income->id,
            'is_reversible' => true,
        ]);

    $response->assertCreated();

    $charge = GeneralCharge::query()->where('name', 'Registration Charge')->firstOrFail();
    expect(SavingsProductCharge::query()->where('general_charge_id', $charge->id)->count())->toBe(0);
});

it('cascade-deletes pivot rows when the charge is deleted', function () {
    $charge = GeneralCharge::factory()->create(['credit_account_id' => $this->income->id]);
    SavingsProductCharge::query()->insert([
        'savings_product_id' => $this->productA->id,
        'general_charge_id' => $charge->id,
        'type' => 'deposit',
        'name' => $charge->name,
        'charge_type' => 'amount',
        'amount' => 100,
        'minimum_amount' => 0,
        'is_reversible' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($this->staff, 'tenant')
        ->deleteJson("/api/v1/tenant/general-charges/{$charge->id}");

    $response->assertOk();
    expect(SavingsProductCharge::query()->where('general_charge_id', $charge->id)->count())->toBe(0);
});

it('rejects a savings-event charge missing trigger_types or saving_product_ids', function () {
    // application=other + where_to_apply=savings is the savings-event lane;
    // both fields are required so the silent-misfire bug can't recur.
    $base = [
        'name' => 'Bad Charge',
        'is_revenue' => 'yes',
        'application' => 'other',
        'where_to_apply' => 'savings',
        'charge_type' => 'amount',
        'amount' => 100,
        'credit_account_id' => $this->income->id,
    ];

    // Missing trigger_types.
    $r1 = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/general-charges', $base + [
            'saving_product_ids' => [$this->productA->id],
        ]);
    $r1->assertStatus(422);
    expect($r1->json('errors'))->toHaveKey('trigger_types');

    // Missing saving_product_ids.
    $r2 = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/general-charges', $base + [
            'trigger_types' => ['deposit'],
        ]);
    $r2->assertStatus(422);
    expect($r2->json('errors'))->toHaveKey('saving_product_ids');

    // Empty arrays count as missing for min:1.
    $r3 = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/general-charges', $base + [
            'saving_product_ids' => [],
            'trigger_types' => [],
        ]);
    $r3->assertStatus(422);
});

it('exposes saving_product_ids and trigger_types in the index response (derived from pivot)', function () {
    $charge = GeneralCharge::factory()->create(['credit_account_id' => $this->income->id]);

    SavingsProductCharge::query()->insert([
        [
            'savings_product_id' => $this->productA->id,
            'general_charge_id' => $charge->id,
            'type' => 'deposit',
            'name' => $charge->name,
            'charge_type' => 'amount',
            'amount' => 100,
            'minimum_amount' => 0,
            'is_reversible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'savings_product_id' => $this->productA->id,
            'general_charge_id' => $charge->id,
            'type' => 'withdraw',
            'name' => $charge->name,
            'charge_type' => 'amount',
            'amount' => 100,
            'minimum_amount' => 0,
            'is_reversible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $response = $this->actingAs($this->staff, 'tenant')
        ->getJson('/api/v1/tenant/general-charges');

    $response->assertOk();
    $row = collect($response->json('data'))->firstWhere('id', $charge->id);

    expect($row['saving_product_ids'])->toEqual([$this->productA->id]);
    // Sort both sides so the assertion doesn't accidentally green on insert-order coincidence.
    expect(collect($row['trigger_types'])->sort()->values()->all())
        ->toEqual(['deposit', 'withdraw']);
});
