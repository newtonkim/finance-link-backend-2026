<?php

namespace Tests\Unit;

use App\Tenant\Http\Resources\LoanPenaltyRuleResource;
use App\Tenant\Modules\Loans\Models\LoanPenaltyRule;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class LoanPenaltyRuleResourceTest extends TestCase
{
    private function makeRule(array $attributes = []): LoanPenaltyRule
    {
        $rule = new LoanPenaltyRule;

        foreach (array_merge([
            'id' => 1,
            'loan_product_id' => 10,
            'system_type' => 'user_created',
            'penalty_type' => 'flat',
            'penalty_rate' => '5.00',
            'grace_days' => 7,
            'amount' => '200.00',
            'applies_to' => 'principal',
            'branch_id' => 1,
            'created_by' => 2,
            'updated_by' => 2,
        ], $attributes) as $key => $value) {
            $rule->$key = $value;
        }

        return $rule;
    }

    public function test_resource_class_exists(): void
    {
        $this->assertTrue(class_exists(LoanPenaltyRuleResource::class));
    }

    public function test_resource_exposes_all_expected_fields(): void
    {
        $resource = new LoanPenaltyRuleResource($this->makeRule());
        $array = $resource->toArray(new Request);

        $expected = [
            'id',
            'loan_product_id',
            'system_type',
            'penalty_type',
            'penalty_rate',
            'grace_days',
            'amount',
            'applies_to',
            'branch_id',
        ];

        foreach ($expected as $field) {
            $this->assertArrayHasKey($field, $array, "Field '{$field}' missing from resource.");
        }
    }

    public function test_resource_maps_correct_values(): void
    {
        $resource = new LoanPenaltyRuleResource($this->makeRule([
            'id' => 5,
            'penalty_type' => 'percentage',
            'penalty_rate' => '3.50',
            'grace_days' => 14,
            'amount' => '0.00',
            'system_type' => 'system',
        ]));

        $array = $resource->toArray(new Request);

        $this->assertEquals(5, $array['id']);
        $this->assertEquals('percentage', $array['penalty_type']);
        $this->assertEquals('3.50', $array['penalty_rate']);
        $this->assertEquals(14, $array['grace_days']);
        $this->assertEquals('system', $array['system_type']);
    }

    public function test_resource_does_not_expose_internal_audit_fields(): void
    {
        $resource = new LoanPenaltyRuleResource($this->makeRule());
        $array = $resource->toArray(new Request);

        $this->assertArrayNotHasKey('created_by', $array);
        $this->assertArrayNotHasKey('updated_by', $array);
        $this->assertArrayNotHasKey('created_at', $array);
        $this->assertArrayNotHasKey('updated_at', $array);
    }
}
