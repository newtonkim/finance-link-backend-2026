<?php

namespace Tests\Unit;

use App\Http\Requests\Tenant\LoanProductFormRequest;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\TestCase;

class LoanProductFormRequestTest extends TestCase
{
    private function validate(array $data, ?string $method = 'POST'): \Illuminate\Validation\Validator
    {
        $request = new LoanProductFormRequest;
        $request->setMethod($method);

        return Validator::make($data, $request->rules());
    }

    public function test_request_class_exists(): void
    {
        $this->assertTrue(class_exists(LoanProductFormRequest::class));
    }

    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new LoanProductFormRequest)->authorize());
    }

    public function test_valid_minimal_payload_passes(): void
    {
        $validator = $this->validate([
            'name' => 'Personal Loan',
            'is_active' => true,
        ]);

        $this->assertFalse($validator->fails(), implode(', ', $validator->errors()->all()));
    }

    public function test_name_is_required(): void
    {
        $validator = $this->validate(['is_active' => true]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('name'));
    }

    public function test_name_must_not_exceed_255_characters(): void
    {
        $validator = $this->validate(['name' => str_repeat('a', 256)]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('name'));
    }

    public function test_numeric_amount_fields_must_be_numeric(): void
    {
        $validator = $this->validate([
            'name' => 'Loan',
            'min_amount' => 'not-a-number',
            'max_amount' => 'not-a-number',
            'interest_rate' => 'not-a-number',
            'penalty_rate' => 'not-a-number',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('min_amount'));
        $this->assertTrue($validator->errors()->has('max_amount'));
        $this->assertTrue($validator->errors()->has('interest_rate'));
        $this->assertTrue($validator->errors()->has('penalty_rate'));
    }

    public function test_interest_method_must_be_valid_enum(): void
    {
        $validator = $this->validate([
            'name' => 'Loan',
            'interest_method' => 'invalid_method',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('interest_method'));
    }

    public function test_interest_period_must_be_valid_enum(): void
    {
        $validator = $this->validate([
            'name' => 'Loan',
            'interest_period' => 'invalid_period',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('interest_period'));
    }

    public function test_duration_type_must_be_valid_enum(): void
    {
        $validator = $this->validate([
            'name' => 'Loan',
            'duration_type' => 'invalid_type',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('duration_type'));
    }

    public function test_repayment_cycle_must_be_valid_enum(): void
    {
        $validator = $this->validate([
            'name' => 'Loan',
            'repayment_cycle' => 'invalid_cycle',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('repayment_cycle'));
    }

    public function test_integer_fields_must_be_integers(): void
    {
        $validator = $this->validate([
            'name' => 'Loan',
            'loan_duration' => 'abc',
            'min_guarantors' => 'abc',
            'max_guarantors' => 'abc',
            'grace_period' => 'abc',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('loan_duration'));
        $this->assertTrue($validator->errors()->has('min_guarantors'));
        $this->assertTrue($validator->errors()->has('max_guarantors'));
        $this->assertTrue($validator->errors()->has('grace_period'));
    }

    public function test_penalty_rules_must_be_array(): void
    {
        $validator = $this->validate([
            'name' => 'Loan',
            'penalty_rules' => 'not-an-array',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('penalty_rules'));
    }

    public function test_penalty_rule_rate_must_be_numeric(): void
    {
        $validator = $this->validate([
            'name' => 'Loan',
            'penalty_rules' => [
                ['penalty_rate' => 'not-a-number'],
            ],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('penalty_rules.0.penalty_rate'));
    }

    public function test_penalty_rule_grace_days_must_be_integer(): void
    {
        $validator = $this->validate([
            'name' => 'Loan',
            'penalty_rules' => [
                ['grace_days' => 'abc'],
            ],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('penalty_rules.0.grace_days'));
    }

    public function test_valid_full_payload_passes(): void
    {
        $validator = $this->validate([
            'name' => 'Business Loan',
            'min_amount' => 5000,
            'max_amount' => 200000,
            'interest_rate' => 18.5,
            'interest_method' => 'reducing_balance',
            'interest_period' => 'monthly',
            'loan_duration' => 24,
            'duration_type' => 'months',
            'repayment_cycle' => 'monthly',
            'min_guarantors' => 1,
            'max_guarantors' => 3,
            'grace_period' => 14,
            'penalty_rate' => 2.5,
            'penalty_type' => 'percentage',
            'is_active' => true,
            'penalty_rules' => [
                [
                    'penalty_type' => 'flat',
                    'penalty_rate' => 500,
                    'grace_days' => 7,
                    'amount' => 500,
                    'applies_to' => 'principal',
                ],
            ],
        ]);

        $this->assertFalse($validator->fails(), implode(', ', $validator->errors()->all()));
    }
}
