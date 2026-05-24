<?php

namespace Tests\Feature\Tenant;

use App\Models\Staff;
use App\Tenant\Modules\Loans\Models\LoanCharge;
use App\Tenant\Modules\Loans\Models\LoanPenaltyRule;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

class LoanProductApiTest extends TenantTestCase
{
    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = Staff::create([
            'name' => 'Loan Admin',
            'email' => 'loanadmin@test.com',
            'password' => Hash::make('password'),
            'role' => 'Admin',
            'is_tenant_admin' => true,
        ]);
    }

    // ─── INDEX ────────────────────────────────────────────────────────────────

    public function test_index_returns_paginated_loan_products(): void
    {
        LoanProduct::create(['name' => 'Product A', 'is_active' => true]);
        LoanProduct::create(['name' => 'Product B', 'is_active' => true]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->getJson('/api/v1/tenant/loan-products');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_index_filters_by_search(): void
    {
        LoanProduct::create(['name' => 'Emergency Loan', 'is_active' => true]);
        LoanProduct::create(['name' => 'Business Loan', 'is_active' => true]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->getJson('/api/v1/tenant/loan-products?search=Emergency');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Emergency Loan');
    }

    public function test_index_filters_by_is_active(): void
    {
        LoanProduct::create(['name' => 'Active Loan',   'is_active' => true]);
        LoanProduct::create(['name' => 'Inactive Loan', 'is_active' => false]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->getJson('/api/v1/tenant/loan-products?is_active=1');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Active Loan');
    }

    // ─── STORE ────────────────────────────────────────────────────────────────

    public function test_store_creates_loan_product_and_returns_201(): void
    {
        $payload = [
            'name' => 'Personal Loan',
            'min_amount' => 1000,
            'max_amount' => 50000,
            'interest_rate' => 12.5,
            'interest_method' => 'flat',
            'interest_period' => 'monthly',
            'loan_duration' => 12,
            'duration_type' => 'months',
            'repayment_cycle' => 'monthly',
            'is_active' => true,
        ];

        $response = $this->actingAs($this->staff, 'tenant')
            ->postJson('/api/v1/tenant/loan-products', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Loan Product Created Successfully.')
            ->assertJsonPath('data.name', 'Personal Loan')
            ->assertJsonPath('data.interest_method', 'flat');

        $this->assertDatabaseHas('loan_products', ['name' => 'Personal Loan']);
    }

    public function test_store_creates_nested_penalty_rules(): void
    {
        $payload = [
            'name' => 'Loan With Penalties',
            'interest_method' => 'flat',
            'interest_period' => 'monthly',
            'duration_type' => 'months',
            'repayment_cycle' => 'monthly',
            'is_active' => true,
            'penalty_rules' => [
                ['penalty_type' => 'flat',       'penalty_rate' => 5.0, 'grace_days' => 7],
                ['penalty_type' => 'percentage', 'penalty_rate' => 2.5, 'grace_days' => 3],
            ],
        ];

        $response = $this->actingAs($this->staff, 'tenant')
            ->postJson('/api/v1/tenant/loan-products', $payload);

        $response->assertStatus(201);

        $productId = $response->json('data.id');
        $this->assertDatabaseHas('loan_penalty_rules', [
            'loan_product_id' => $productId,
            'penalty_type' => 'flat',
        ]);
        $this->assertDatabaseHas('loan_penalty_rules', [
            'loan_product_id' => $productId,
            'penalty_type' => 'percentage',
        ]);
    }

    public function test_store_syncs_charge_ids_and_penalty_grace_days(): void
    {
        $charge = LoanCharge::create([
            'name' => 'Processing Fee',
            'category' => 'processing_fee',
            'charge_type' => 'percentage',
            'value' => 2,
            'frequency' => 'one_time',
            'grace_days' => 0,
            'max_value_type' => 'none',
            'is_active' => true,
        ]);

        $payload = [
            'name' => 'Charge Synced Loan',
            'is_active' => true,
            'penalty_grace_days' => 3,
            'charge_ids' => [$charge->id],
        ];

        $response = $this->actingAs($this->staff, 'tenant')
            ->postJson('/api/v1/tenant/loan-products', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.penalty_grace_days', 3)
            ->assertJsonPath('data.charge_ids.0', $charge->id);

        $this->assertDatabaseHas('loan_product_charge', [
            'loan_product_id' => $response->json('data.id'),
            'loan_charge_id' => $charge->id,
        ]);
    }

    public function test_store_returns_422_when_name_missing(): void
    {
        $response = $this->actingAs($this->staff, 'tenant')
            ->postJson('/api/v1/tenant/loan-products', ['is_active' => true]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_returns_422_on_duplicate_name(): void
    {
        LoanProduct::create(['name' => 'Duplicate Loan', 'is_active' => true]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->postJson('/api/v1/tenant/loan-products', [
                'name' => 'Duplicate Loan',
                'is_active' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // ─── SHOW ─────────────────────────────────────────────────────────────────

    public function test_show_returns_loan_product_with_penalty_rules(): void
    {
        $product = LoanProduct::create([
            'name' => 'Show Loan',
            'interest_method' => 'reducing_balance',
            'is_active' => true,
        ]);
        LoanPenaltyRule::create([
            'loan_product_id' => $product->id,
            'penalty_type' => 'flat',
            'penalty_rate' => 3.0,
        ]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->getJson("/api/v1/tenant/loan-products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Show Loan')
            ->assertJsonPath('data.interest_method', 'reducing_balance')
            ->assertJsonCount(1, 'data.penalty_rules');
    }

    // ─── UPDATE ───────────────────────────────────────────────────────────────

    public function test_update_modifies_loan_product(): void
    {
        $product = LoanProduct::create(['name' => 'Old Name', 'is_active' => true]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->putJson("/api/v1/tenant/loan-products/{$product->id}", [
                'name' => 'New Name',
                'is_active' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Loan Product Updated Successfully.')
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('loan_products', ['id' => $product->id, 'name' => 'New Name']);
    }

    public function test_update_replaces_penalty_rules(): void
    {
        $product = LoanProduct::create(['name' => 'Loan', 'is_active' => true]);
        LoanPenaltyRule::create(['loan_product_id' => $product->id, 'penalty_type' => 'old_type']);

        $response = $this->actingAs($this->staff, 'tenant')
            ->putJson("/api/v1/tenant/loan-products/{$product->id}", [
                'name' => 'Loan',
                'is_active' => true,
                'penalty_rules' => [
                    ['penalty_type' => 'new_type', 'penalty_rate' => 3.0, 'grace_days' => 5],
                ],
            ]);

        $response->assertStatus(200);

        $rules = $product->fresh()->penaltyRules;
        $this->assertCount(1, $rules);
        $this->assertEquals('new_type', $rules->first()->penalty_type);
    }

    public function test_update_allows_same_name_on_self(): void
    {
        $product = LoanProduct::create(['name' => 'Same Name', 'is_active' => true]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->putJson("/api/v1/tenant/loan-products/{$product->id}", [
                'name' => 'Same Name',
                'is_active' => false,
            ]);

        $response->assertStatus(200);
    }

    // ─── DESTROY ──────────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_loan_product(): void
    {
        $product = LoanProduct::create(['name' => 'To Delete', 'is_active' => true]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->deleteJson("/api/v1/tenant/loan-products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Loan Product Deleted Successfully.');

        $this->assertSoftDeleted('loan_products', ['id' => $product->id]);
    }

    public function test_destroy_returns_404_for_missing_product(): void
    {
        $response = $this->actingAs($this->staff, 'tenant')
            ->deleteJson('/api/v1/tenant/loan-products/99999');

        $response->assertStatus(404);
    }

    // ─── RESPONSE SHAPE ───────────────────────────────────────────────────────

    public function test_response_includes_interest_method_field(): void
    {
        $product = LoanProduct::create([
            'name' => 'Rate Loan',
            'interest_method' => 'reducing_balance',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->getJson("/api/v1/tenant/loan-products/{$product->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.interest_method', 'reducing_balance');
    }
}
