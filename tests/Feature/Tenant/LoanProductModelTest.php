<?php

namespace Tests\Feature\Tenant;

use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TenantTestCase;

class LoanProductModelTest extends TenantTestCase
{
    public function test_loan_product_uses_tenant_connection(): void
    {
        $model = new LoanProduct;

        $this->assertEquals('tenant', $model->getConnectionName());
    }

    public function test_loan_product_uses_soft_deletes(): void
    {
        $this->assertContains(SoftDeletes::class, class_uses_recursive(LoanProduct::class));
    }

    public function test_loan_product_has_all_fillable_columns(): void
    {
        $expected = [
            'code',
            'name',
            'description',
            'min_amount',
            'max_amount',
            'interest_rate',
            'interest_method',
            'repayment_structure',
            'interest_period',
            'loan_duration',
            'duration_type',
            'repayment_cycle',
            'min_guarantors',
            'max_guarantors',
            'grace_period',
            'penalty_rate',
            'penalty_type',
            'requires_approval',
            'allow_top_up',
            'allow_reschedule',
            'processing_fee_type',
            'processing_fee_value',
            'loan_portfolio_account_id',
            'interest_income_account_id',
            'interest_receivable_account_id',
            'penalty_income_account_id',
            'penalty_receivable_account_id',
            'disbursement_account_id',
            'created_by',
            'updated_by',
            'is_active',
        ];

        $fillable = (new LoanProduct)->getFillable();

        foreach ($expected as $column) {
            $this->assertContains($column, $fillable, "Expected '{$column}' to be fillable.");
        }
    }

    public function test_loan_product_casts_decimal_fields(): void
    {
        $product = LoanProduct::create([
            'name' => 'Personal Loan',
            'min_amount' => 1000,
            'max_amount' => 50000,
            'interest_rate' => 12.5,
            'penalty_rate' => 2.0,
            'processing_fee_value' => 1.5,
            'is_active' => true,
        ]);

        $this->assertIsFloat((float) $product->min_amount);
        $this->assertIsFloat((float) $product->max_amount);
        $this->assertIsFloat((float) $product->interest_rate);
        $this->assertIsFloat((float) $product->penalty_rate);
        $this->assertIsFloat((float) $product->processing_fee_value);
    }

    public function test_loan_product_casts_boolean_fields(): void
    {
        $product = LoanProduct::create([
            'name' => 'Business Loan',
            'requires_approval' => 1,
            'allow_top_up' => 1,
            'allow_reschedule' => 1,
            'is_active' => 1,
        ]);

        $this->assertIsBool($product->requires_approval);
        $this->assertIsBool($product->allow_top_up);
        $this->assertIsBool($product->allow_reschedule);
        $this->assertIsBool($product->is_active);
        $this->assertTrue($product->is_active);
    }

    public function test_loan_product_casts_integer_fields(): void
    {
        $product = LoanProduct::create([
            'name' => 'Emergency Loan',
            'loan_duration' => 12,
            'min_guarantors' => 1,
            'max_guarantors' => 3,
            'grace_period' => 7,
        ]);

        $this->assertIsInt($product->loan_duration);
        $this->assertIsInt($product->min_guarantors);
        $this->assertIsInt($product->max_guarantors);
        $this->assertIsInt($product->grace_period);
    }

    public function test_loan_product_can_be_soft_deleted(): void
    {
        $product = LoanProduct::create([
            'name' => 'Soft Delete Loan',
            'is_active' => true,
        ]);

        $product->delete();

        $this->assertSoftDeleted('loan_products', ['id' => $product->id]);
        $this->assertNull(LoanProduct::find($product->id));
    }

    public function test_loan_product_has_penalty_rules_relationship(): void
    {
        $product = new LoanProduct;

        $this->assertInstanceOf(HasMany::class, $product->penaltyRules());
    }

    public function test_loan_product_has_account_and_audit_relationships(): void
    {
        $product = new LoanProduct;

        $this->assertInstanceOf(BelongsTo::class, $product->portfolioAccount());
        $this->assertInstanceOf(BelongsTo::class, $product->interestIncomeAccount());
        $this->assertInstanceOf(BelongsTo::class, $product->interestReceivableAccount());
        $this->assertInstanceOf(BelongsTo::class, $product->penaltyIncomeAccount());
        $this->assertInstanceOf(BelongsTo::class, $product->penaltyReceivableAccount());
        $this->assertInstanceOf(BelongsTo::class, $product->disbursementAccount());
        $this->assertInstanceOf(BelongsTo::class, $product->creator());
        $this->assertInstanceOf(BelongsTo::class, $product->updater());
    }
}
