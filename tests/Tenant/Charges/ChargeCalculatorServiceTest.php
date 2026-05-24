<?php

use App\Tenant\Modules\Charges\Services\ChargeCalculatorService;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;

it('returns null when no savings_product_charge has a general_charge_id for the given event type', function () {
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
    SavingsProductCharge::factory()->create([
        'savings_product_id' => $product->id,
        'type' => 'deposit',
        'general_charge_id' => null,
    ]);

    $service = new ChargeCalculatorService();
    $result = $service->resolveForSavings($account->id, 'deposit', 500.00);

    expect($result)->toBeNull();
});

it('returns correct fixed fee when a general_charge_id is linked', function () {
    $generalCharge = GeneralCharge::factory()->create([
        'charge_type' => 'amount',
        'amount' => 50.00,
        'interval_type' => 'deposit',
    ]);
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
    SavingsProductCharge::factory()->create([
        'savings_product_id' => $product->id,
        'type' => 'deposit',
        'general_charge_id' => $generalCharge->id,
    ]);

    $service = new ChargeCalculatorService();
    $result = $service->resolveForSavings($account->id, 'deposit', 1000.00);

    expect($result)->not->toBeNull()
        ->and($result['fee'])->toBe(50.0)
        ->and($result['charge']->id)->toBe($generalCharge->id);
});

it('computes a percentage fee correctly', function () {
    $generalCharge = GeneralCharge::factory()->create([
        'charge_type' => 'percentage',
        'amount' => 2.50,
        'interval_type' => 'deposit',
    ]);
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
    SavingsProductCharge::factory()->create([
        'savings_product_id' => $product->id,
        'type' => 'deposit',
        'general_charge_id' => $generalCharge->id,
    ]);

    $service = new ChargeCalculatorService();
    $result = $service->resolveForSavings($account->id, 'deposit', 1000.00);

    expect($result['fee'])->toBe(25.0);
});

it('returns null when the linked general_charge is inactive', function () {
    // Inactive charges must not be applied to deposits / withdrawals / transfers —
    // the Active toggle in the General Charges UI is supposed to disable them.
    $generalCharge = GeneralCharge::factory()->create([
        'charge_type' => 'amount',
        'amount' => 50.00,
        'is_active' => false,
    ]);
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
    SavingsProductCharge::factory()->create([
        'savings_product_id' => $product->id,
        'type' => 'deposit',
        'general_charge_id' => $generalCharge->id,
    ]);

    $service = new ChargeCalculatorService();
    $result = $service->resolveForSavings($account->id, 'deposit', 1000.00);

    expect($result)->toBeNull();
});
