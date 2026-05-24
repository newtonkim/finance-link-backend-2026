<?php

namespace Tests\Feature\Tenant;

use App\Tenant\Modules\Loans\Models\LoanPenaltyRule;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tests\TenantTestCase;

class LoanPenaltyRuleModelTest extends TenantTestCase
{
    public function test_loan_penalty_rule_uses_tenant_connection(): void
    {
        $model = new LoanPenaltyRule;

        $this->assertEquals('tenant', $model->getConnectionName());
    }

    public function test_loan_penalty_rule_has_all_fillable_columns(): void
    {
        $expected = [
            'loan_product_id',
            'system_type',
            'penalty_type',
            'penalty_rate',
            'grace_days',
            'amount',
            'applies_to',
            'branch_id',
            'created_by',
            'updated_by',
        ];

        $fillable = (new LoanPenaltyRule)->getFillable();

        foreach ($expected as $column) {
            $this->assertContains($column, $fillable, "Expected '{$column}' to be fillable.");
        }
    }

    public function test_loan_penalty_rule_casts_decimal_fields(): void
    {
        $product = LoanProduct::create(['name' => 'Test Product', 'is_active' => true]);

        $rule = LoanPenaltyRule::create([
            'loan_product_id' => $product->id,
            'penalty_rate' => 5.5,
            'amount' => 200.00,
        ]);

        $this->assertIsFloat((float) $rule->penalty_rate);
        $this->assertIsFloat((float) $rule->amount);
    }

    public function test_loan_penalty_rule_casts_integer_fields(): void
    {
        $product = LoanProduct::create(['name' => 'Test Product 2', 'is_active' => true]);

        $rule = LoanPenaltyRule::create([
            'loan_product_id' => $product->id,
            'grace_days' => 7,
        ]);

        $this->assertIsInt($rule->grace_days);
    }

    public function test_loan_penalty_rule_belongs_to_loan_product(): void
    {
        $model = new LoanPenaltyRule;

        $this->assertInstanceOf(BelongsTo::class, $model->loanProduct());
    }

    public function test_loan_penalty_rule_is_associated_with_correct_product(): void
    {
        $product = LoanProduct::create(['name' => 'Association Test', 'is_active' => true]);

        $rule = LoanPenaltyRule::create([
            'loan_product_id' => $product->id,
            'penalty_type' => 'flat',
            'penalty_rate' => 3.0,
            'grace_days' => 5,
        ]);

        $this->assertEquals($product->id, $rule->loanProduct->id);
        $this->assertEquals('Association Test', $rule->loanProduct->name);
    }
}
