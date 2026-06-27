<?php

use App\Models\Staff;
use App\Tenant\Modules\Savings\Models\SavingsProduct;

beforeEach(function () {
    $this->user = Staff::factory()->create([
        'is_tenant_admin' => true,
    ]);

    $this->actingAs($this->user, 'sanctum');
});

it('can list savings products', function () {
    SavingsProduct::factory()->count(3)->create();

    $response = $this->getJson('http://test.mfukopro.test/api/v1/tenant/savings-products');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data');
});

it('can create a savings product', function () {
    $data = [
        'name' => 'Premium Savings',
        'type' => 'standard',
        'minimum_balance' => 1000,
        'minimum_maturity_months' => 6,
        'dormancy_period_months' => 12,
        'status' => 'active',
    ];

    $response = $this->postJson('http://test.mfukopro.test/api/v1/tenant/savings-products', $data);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Premium Savings');

    $this->assertDatabaseHas('savings_products', ['name' => 'Premium Savings'], 'tenant');
});

it('can update a savings product', function () {
    $product = SavingsProduct::factory()->create(['name' => 'Old Name']);

    $response = $this->putJson("http://test.mfukopro.test/api/v1/tenant/savings-products/{$product->id}", [
        'name' => 'New Name',
        'type' => $product->type,
        'minimum_balance' => $product->minimum_balance,
        'minimum_maturity_months' => $product->minimum_maturity_months,
        'dormancy_period_months' => $product->dormancy_period_months,
    ]);

    $response->assertStatus(200);
    expect($product->fresh()->name)->toBe('New Name');
});

it('can delete a savings product', function () {
    $product = SavingsProduct::factory()->create();

    $response = $this->deleteJson("http://test.mfukopro.test/api/v1/tenant/savings-products/{$product->id}");

    $response->assertStatus(200);
    $this->assertSoftDeleted('savings_products', ['id' => $product->id], 'tenant');
});

it('validates savings product creation', function () {
    $response = $this->postJson('http://test.mfukopro.test/api/v1/tenant/savings-products', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'type']);
});
