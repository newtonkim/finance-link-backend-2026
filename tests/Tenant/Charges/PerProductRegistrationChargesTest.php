<?php

use App\Models\Staff;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->income = ChartOfAccount::query()->where('gl_code', '42997')->first() ?? ChartOfAccount::create([
        'gl_code'         => '42997',
        'name'            => 'Test Charge Income',
        'account_type'    => 'INCOME',
        'account_subtype' => 'Fee Income',
        'normal_balance'  => 'CR',
        'level'           => 3,
        'is_control'      => false,
        'is_postable'     => true,
        'is_active'       => true,
    ]);

    $this->product = SavingsProduct::factory()->create();

    $this->staff = Staff::firstOrCreate(
        ['email' => 'admin@gap-reg-test.com'],
        [
            'name'            => 'Tenant Admin',
            'password'        => Hash::make('password'),
            'role'            => 'Admin',
            'is_tenant_admin' => true,
        ]
    );
});

it('migration extends savings_product_charges.type enum to include registration', function () {
    // The migration that extends savings_product_charges.type with 'registration'
    // is applied by the test bootstrap's normal migration flow. We verify the
    // ENUM accepts the new value by inserting a row and reading it back — if
    // the migration is reverted, the insert fails with "Data truncated for
    // column 'type'".

    // Build a charge + pivot row with the new type. If the ENUM was not extended
    // this insert raises "Data truncated for column 'type'".
    $charge = GeneralCharge::factory()->create([
        'application'       => 'on_registration',
        'credit_account_id' => $this->income->id,
    ]);

    SavingsProductCharge::on('tenant')->insert([
        'savings_product_id' => $this->product->id,
        'general_charge_id'  => $charge->id,
        'type'               => 'registration',
        'name'               => $charge->name,
        'charge_type'        => 'amount',
        'amount'             => 5000,
        'minimum_amount'     => 0,
        'is_reversible'      => true,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    $row = DB::connection('tenant')->table('savings_product_charges')
        ->where('general_charge_id', $charge->id)
        ->first();

    expect($row->type)->toBe('registration');
});

it('creates pivot rows with type=registration when application=on_registration + saving_product_ids', function () {
    $productB = SavingsProduct::factory()->create();

    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/general-charges', [
            'name'               => 'Account Opening Fee',
            'is_revenue'         => 'yes',
            'application'        => 'on_registration',
            'charge_type'        => 'amount',
            'amount'             => 5000,
            'credit_account_id'  => $this->income->id,
            'saving_product_ids' => [$this->product->id, $productB->id],
        ]);

    $response->assertCreated();

    $chargeId = $response->json('data.id');
    $rows = SavingsProductCharge::on('tenant')
        ->where('general_charge_id', $chargeId)
        ->get();

    expect($rows)->toHaveCount(2);
    expect($rows->pluck('type')->unique()->values()->all())->toEqual(['registration']);
    expect($rows->pluck('savings_product_id')->sort()->values()->all())
        ->toEqual(collect([$this->product->id, $productB->id])->sort()->values()->all());
});

it('keeps universal-receivable mode when application=on_registration with no saving_product_ids', function () {
    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/general-charges', [
            'name'              => 'Universal Membership Fee',
            'is_revenue'        => 'yes',
            'application'       => 'on_registration',
            'charge_type'       => 'amount',
            'amount'            => 2000,
            'credit_account_id' => $this->income->id,
        ]);

    $response->assertCreated();

    $chargeId = $response->json('data.id');
    expect(SavingsProductCharge::on('tenant')->where('general_charge_id', $chargeId)->count())
        ->toBe(0);
});

it('rejects on_registration charge update when saving_product_ids includes a non-existent product', function () {
    $charge = GeneralCharge::factory()->create([
        'application'       => 'on_registration',
        'credit_account_id' => $this->income->id,
    ]);

    $response = $this->actingAs($this->staff, 'tenant')
        ->putJson("/api/v1/tenant/general-charges/{$charge->id}", [
            'name'               => $charge->name,
            'is_revenue'         => 'yes',
            'application'        => 'on_registration',
            'charge_type'        => 'amount',
            'amount'             => $charge->amount,
            'credit_account_id'  => $this->income->id,
            'saving_product_ids' => [999999],
        ]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('saving_product_ids.0');
});

use App\Tenant\Modules\Charges\Contracts\ChargeCalculatorServiceInterface;

it('resolveForRegistration returns matching active charges for a product', function () {
    $charge1 = GeneralCharge::factory()->create([
        'application'       => 'on_registration',
        'amount'            => 5000,
        'credit_account_id' => $this->income->id,
        'is_active'         => true,
    ]);
    $charge2 = GeneralCharge::factory()->create([
        'application'       => 'on_registration',
        'amount'            => 2000,
        'credit_account_id' => $this->income->id,
        'is_active'         => true,
    ]);

    foreach ([$charge1, $charge2] as $c) {
        SavingsProductCharge::on('tenant')->insert([
            'savings_product_id' => $this->product->id,
            'general_charge_id'  => $c->id,
            'type'               => 'registration',
            'name'               => $c->name,
            'charge_type'        => 'amount',
            'amount'             => $c->amount,
            'minimum_amount'     => 0,
            'is_reversible'      => true,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    $calculator = app(ChargeCalculatorServiceInterface::class);
    $result = $calculator->resolveForRegistration($this->product->id);

    expect($result)->toHaveCount(2);
    expect(collect($result)->pluck('general_charge_id')->sort()->values()->all())
        ->toEqual(collect([$charge1->id, $charge2->id])->sort()->values()->all());
    expect(collect($result)->pluck('amount')->sort()->values()->all())
        ->toEqual([2000.0, 5000.0]);
});

it('resolveForRegistration excludes inactive charges', function () {
    $charge = GeneralCharge::factory()->create([
        'application'       => 'on_registration',
        'credit_account_id' => $this->income->id,
        'is_active'         => false,
    ]);
    SavingsProductCharge::on('tenant')->insert([
        'savings_product_id' => $this->product->id,
        'general_charge_id'  => $charge->id,
        'type'               => 'registration',
        'name'               => $charge->name,
        'charge_type'        => 'amount',
        'amount'             => 100,
        'minimum_amount'     => 0,
        'is_reversible'      => true,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    $calculator = app(ChargeCalculatorServiceInterface::class);
    expect($calculator->resolveForRegistration($this->product->id))->toBe([]);
});

it('resolveForRegistration returns empty for a product with no registration charges', function () {
    $calculator = app(ChargeCalculatorServiceInterface::class);
    expect($calculator->resolveForRegistration($this->product->id))->toBe([]);
});

use App\Models\Member;
use App\Tenant\Modules\Members\Models\MemberCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

it('member registration deducts product-scoped registration charges from initial deposit', function () {
    $charge = GeneralCharge::factory()->create([
        'application'       => 'on_registration',
        'amount'            => 5000,
        'credit_account_id' => $this->income->id,
        'is_active'         => true,
    ]);
    SavingsProductCharge::on('tenant')->insert([
        'savings_product_id' => $this->product->id,
        'general_charge_id'  => $charge->id,
        'type'               => 'registration',
        'name'               => $charge->name,
        'charge_type'        => 'amount',
        'amount'             => 5000,
        'minimum_amount'     => 0,
        'is_reversible'      => true,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    // Force the default savings product to be our test product by updating the product name
    $this->product->update(['name' => 'General Savings Account']);

    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/members', [
            'member_type'     => 'new_member',
            'name'            => 'Test Member',
            'email'           => 'test@member.com',
            'phone'           => '+256700000001',
            'gender'          => 'male',
            'dob'             => '1990-01-01',
            'address'         => 'Kampala',
            'nationality'     => 'Uganda',
            'marital_status'  => 'single',
            'initial_deposit' => 20000,
        ]);

    $response->assertStatus(200);
    $memberId = $response->json('data.id');
    $account = SavingsAccount::on('tenant')->where('member_id', $memberId)->latest()->first();

    expect((float) $account->balance)->toBe(15000.0);
    // The product-scoped charge should NOT have created a MemberCharge receivable.
    expect(MemberCharge::on('tenant')->where('member_id', $memberId)->where('general_charge_id', $charge->id)->count())
        ->toBe(0);
});

it('member registration rejects when initial deposit is less than product-scoped charges total', function () {
    $charge = GeneralCharge::factory()->create([
        'application'       => 'on_registration',
        'amount'            => 5000,
        'credit_account_id' => $this->income->id,
        'is_active'         => true,
    ]);
    SavingsProductCharge::on('tenant')->insert([
        'savings_product_id' => $this->product->id,
        'general_charge_id'  => $charge->id,
        'type'               => 'registration',
        'name'               => $charge->name,
        'charge_type'        => 'amount',
        'amount'             => 5000,
        'minimum_amount'     => 0,
        'is_reversible'      => true,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    // Force the default savings product to be our test product
    $this->product->update(['name' => 'General Savings Account']);

    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/members', [
            'member_type'     => 'new_member',
            'name'            => 'Underfunded Member',
            'email'           => 'under@member.com',
            'phone'           => '+256700000002',
            'gender'          => 'male',
            'dob'             => '1990-01-01',
            'address'         => 'Kampala',
            'nationality'     => 'Uganda',
            'marital_status'  => 'single',
            'initial_deposit' => 3000,
        ]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('initial_deposit');
    expect(Member::on('tenant')->where('email', 'under@member.com')->exists())->toBeFalse();
});

it('general-product-charges endpoint returns pivot-backed list for a product', function () {
    $charge = GeneralCharge::factory()->create([
        'application'       => 'on_registration',
        'amount'            => 5000,
        'credit_account_id' => $this->income->id,
        'is_active'         => true,
    ]);
    SavingsProductCharge::on('tenant')->insert([
        'savings_product_id' => $this->product->id,
        'general_charge_id'  => $charge->id,
        'type'               => 'registration',
        'name'               => $charge->name,
        'charge_type'        => 'amount',
        'amount'             => 5000,
        'minimum_amount'     => 0,
        'is_reversible'      => true,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/global/general-product-charges', [
            'id'   => $this->product->id,
            'type' => 'onboarding',
        ]);

    $response->assertOk();
    $items = collect($response->json('payload.data') ?? $response->json('data.data') ?? []);
    $row = $items->firstWhere('id', $charge->id);

    expect($row)->not->toBeNull()
        ->and((float) $row['charge_amount'])->toBe(5000.0);
});

it('general-product-charges endpoint returns empty for a product with no registration charges', function () {
    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/global/general-product-charges', [
            'id'   => $this->product->id,
            'type' => 'onboarding',
        ]);

    $response->assertOk();
    $items = collect($response->json('payload.data') ?? $response->json('data.data') ?? []);
    expect($items->count())->toBe(0);
});

it('universal applyRegistrationCharges excludes charges with type=registration pivot rows', function () {
    $universal = GeneralCharge::factory()->create([
        'application'       => 'on_registration',
        'amount'            => 2000,
        'credit_account_id' => $this->income->id,
        'is_active'         => true,
    ]);
    $scoped = GeneralCharge::factory()->create([
        'application'       => 'on_registration',
        'amount'            => 5000,
        'credit_account_id' => $this->income->id,
        'is_active'         => true,
    ]);
    SavingsProductCharge::on('tenant')->insert([
        'savings_product_id' => $this->product->id,
        'general_charge_id'  => $scoped->id,
        'type'               => 'registration',
        'name'               => $scoped->name,
        'charge_type'        => 'amount',
        'amount'             => 5000,
        'minimum_amount'     => 0,
        'is_reversible'      => true,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    // Force the default savings product to be our test product
    $this->product->update(['name' => 'General Savings Account']);

    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/members', [
            'member_type'     => 'new_member',
            'name'            => 'Mixed Member',
            'email'           => 'mixed@member.com',
            'phone'           => '+256700000003',
            'gender'          => 'male',
            'dob'             => '1990-01-01',
            'address'         => 'Kampala',
            'nationality'     => 'Uganda',
            'marital_status'  => 'single',
            'initial_deposit' => 20000,
        ]);

    $response->assertStatus(200);
    $memberId = $response->json('data.id');

    expect(MemberCharge::on('tenant')->where('member_id', $memberId)->where('general_charge_id', $universal->id)->count())
        ->toBe(1);
    expect(MemberCharge::on('tenant')->where('member_id', $memberId)->where('general_charge_id', $scoped->id)->count())
        ->toBe(0);
});
