<?php

namespace Tests\Feature\Tenant;

use App\Tenant\Modules\Loans\Contracts\LoanProductServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanPenaltyRule;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Services\LoanProductService;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TenantTestCase;

class LoanProductServiceTest extends TenantTestCase
{
    private LoanProductService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LoanProductService;
    }

    public function test_service_implements_interface(): void
    {
        $this->assertInstanceOf(LoanProductServiceInterface::class, $this->service);
    }

    public function test_list_returns_paginated_results(): void
    {
        LoanProduct::create(['name' => 'Product A', 'is_active' => true]);
        LoanProduct::create(['name' => 'Product B', 'is_active' => true]);

        $result = $this->service->list();

        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
        $this->assertGreaterThanOrEqual(2, $result->total());
    }

    public function test_list_filters_by_search(): void
    {
        LoanProduct::create(['name' => 'Emergency Loan', 'is_active' => true]);
        LoanProduct::create(['name' => 'Business Loan', 'is_active' => true]);

        $result = $this->service->list(['search' => 'Emergency']);

        $this->assertEquals(1, $result->total());
        $this->assertEquals('Emergency Loan', $result->items()[0]->name);
    }

    public function test_list_filters_by_is_active(): void
    {
        LoanProduct::create(['name' => 'Active Product', 'is_active' => true]);
        LoanProduct::create(['name' => 'Inactive Product', 'is_active' => false]);

        $result = $this->service->list(['is_active' => true]);

        $this->assertEquals(1, $result->total());
        $this->assertTrue($result->items()[0]->is_active);
    }

    public function test_list_eager_loads_penalty_rules(): void
    {
        $product = LoanProduct::create(['name' => 'Loan With Rules', 'is_active' => true]);
        LoanPenaltyRule::create(['loan_product_id' => $product->id, 'penalty_rate' => 2.5]);

        $result = $this->service->list();

        $this->assertTrue($result->items()[0]->relationLoaded('penaltyRules'));
    }

    public function test_create_persists_loan_product(): void
    {
        $data = [
            'name' => 'Personal Loan',
            'min_amount' => 1000,
            'max_amount' => 50000,
            'interest_rate' => 12.5,
            'is_active' => true,
        ];

        $product = $this->service->create($data);

        $this->assertInstanceOf(LoanProduct::class, $product);
        $this->assertDatabaseHas('loan_products', ['name' => 'Personal Loan']);
    }

    public function test_create_persists_nested_penalty_rules(): void
    {
        $data = [
            'name' => 'Loan With Penalties',
            'is_active' => true,
            'penalty_rules' => [
                ['penalty_type' => 'flat',       'penalty_rate' => 5.0, 'grace_days' => 7],
                ['penalty_type' => 'percentage', 'penalty_rate' => 2.5, 'grace_days' => 3],
            ],
        ];

        $product = $this->service->create($data);

        $this->assertCount(2, $product->penaltyRules);
        $this->assertDatabaseHas('loan_penalty_rules', [
            'loan_product_id' => $product->id,
            'penalty_type' => 'flat',
        ]);
    }

    public function test_create_without_penalty_rules_still_succeeds(): void
    {
        $product = $this->service->create(['name' => 'Simple Loan', 'is_active' => true]);

        $this->assertInstanceOf(LoanProduct::class, $product);
        $this->assertCount(0, $product->penaltyRules);
    }

    public function test_update_modifies_loan_product(): void
    {
        $product = LoanProduct::create(['name' => 'Old Name', 'is_active' => true]);

        $result = $this->service->update($product, ['name' => 'New Name']);

        $this->assertTrue($result);
        $this->assertEquals('New Name', $product->fresh()->name);
    }

    public function test_update_replaces_penalty_rules(): void
    {
        $product = LoanProduct::create(['name' => 'Loan', 'is_active' => true]);
        LoanPenaltyRule::create(['loan_product_id' => $product->id, 'penalty_type' => 'old_type']);

        $this->service->update($product, [
            'name' => 'Loan',
            'penalty_rules' => [
                ['penalty_type' => 'new_type', 'penalty_rate' => 3.0, 'grace_days' => 5],
            ],
        ]);

        $rules = $product->fresh()->penaltyRules;
        $this->assertCount(1, $rules);
        $this->assertEquals('new_type', $rules->first()->penalty_type);
    }

    public function test_update_clears_penalty_rules_when_none_provided(): void
    {
        $product = LoanProduct::create(['name' => 'Loan', 'is_active' => true]);
        LoanPenaltyRule::create(['loan_product_id' => $product->id, 'penalty_type' => 'flat']);

        $this->service->update($product, ['name' => 'Loan']);

        $this->assertCount(0, $product->fresh()->penaltyRules);
    }

    public function test_delete_soft_deletes_loan_product(): void
    {
        $product = LoanProduct::create(['name' => 'To Delete', 'is_active' => true]);

        $this->service->delete($product);

        $this->assertSoftDeleted('loan_products', ['id' => $product->id]);
        $this->assertNull(LoanProduct::find($product->id));
    }
}
