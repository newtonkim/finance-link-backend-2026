<?php

namespace Tests\Unit;

use App\Tenant\Http\Resources\LoanProductResource;
use App\Tenant\Modules\Loans\Models\LoanPenaltyRule;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class LoanProductResourceTest extends TestCase
{
    private function makeProduct(array $attributes = []): LoanProduct
    {
        $product = new LoanProduct;

        foreach (array_merge([
            'id' => 1,
            'name' => 'Business Loan',
            'min_amount' => '5000.00',
            'max_amount' => '200000.00',
            'interest_rate' => '18.50',
            'interest_method' => 'reducing_balance',
            'interest_period' => 'monthly',
            'loan_duration' => 24,
            'duration_type' => 'months',
            'repayment_cycle' => 'monthly',
            'min_guarantors' => 1,
            'max_guarantors' => 3,
            'grace_period' => 14,
            'penalty_rate' => '2.50',
            'penalty_type' => 'percentage',
            'is_active' => true,
        ], $attributes) as $key => $value) {
            $product->$key = $value;
        }

        return $product;
    }

    public function test_resource_class_exists(): void
    {
        $this->assertTrue(class_exists(LoanProductResource::class));
    }

    public function test_resource_exposes_all_expected_fields(): void
    {
        $resource = new LoanProductResource($this->makeProduct());
        $array = $resource->toArray(new Request);

        $expected = [
            'id',
            'name',
            'min_amount',
            'max_amount',
            'interest_rate',
            'interest_method',
            'interest_period',
            'loan_duration',
            'duration_type',
            'repayment_cycle',
            'min_guarantors',
            'max_guarantors',
            'grace_period',
            'penalty_rate',
            'penalty_type',
            'is_active',
        ];

        foreach ($expected as $field) {
            $this->assertArrayHasKey($field, $array, "Field '{$field}' missing from resource.");
        }
    }

    public function test_resource_exposes_interest_method_for_dropdown(): void
    {
        $resource = new LoanProductResource($this->makeProduct(['interest_method' => 'flat']));
        $array = $resource->toArray(new Request);

        $this->assertEquals('flat', $array['interest_method']);

        $resource2 = new LoanProductResource($this->makeProduct(['interest_method' => 'reducing_balance']));
        $array2 = $resource2->toArray(new Request);

        $this->assertEquals('reducing_balance', $array2['interest_method']);
    }

    public function test_resource_maps_correct_values(): void
    {
        $resource = new LoanProductResource($this->makeProduct([
            'id' => 7,
            'name' => 'Emergency Loan',
            'is_active' => false,
        ]));

        $array = $resource->toArray(new Request);

        $this->assertEquals(7, $array['id']);
        $this->assertEquals('Emergency Loan', $array['name']);
        $this->assertFalse($array['is_active']);
    }

    public function test_penalty_rules_included_when_loaded(): void
    {
        $product = $this->makeProduct();

        $rule = new LoanPenaltyRule;
        $rule->id = 1;
        $rule->penalty_type = 'flat';
        $rule->penalty_rate = '5.00';
        $rule->grace_days = 7;

        $product->setRelation('penaltyRules', new Collection([$rule]));

        $array = (new LoanProductResource($product))->toArray(new Request);

        $this->assertArrayHasKey('penalty_rules', $array);
        $this->assertCount(1, $array['penalty_rules']);
    }

    public function test_penalty_rules_omitted_when_not_loaded(): void
    {
        $product = $this->makeProduct();
        // penaltyRules relation NOT loaded — use resolve() to run the full
        // MissingValue filter pipeline that whenLoaded() depends on
        $array = (new LoanProductResource($product))->resolve(new Request);

        $this->assertArrayNotHasKey('penalty_rules', $array);
    }

    public function test_resource_does_not_expose_timestamps(): void
    {
        $resource = new LoanProductResource($this->makeProduct());
        $array = $resource->toArray(new Request);

        $this->assertArrayNotHasKey('created_at', $array);
        $this->assertArrayNotHasKey('updated_at', $array);
        $this->assertArrayNotHasKey('deleted_at', $array);
    }
}
