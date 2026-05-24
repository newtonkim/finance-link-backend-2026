<?php

namespace Tests\Unit;

use App\Http\Requests\Tenant\UpdateLoanSettingsRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\TestCase;

class UpdateLoanSettingsRescheduleRulesTest extends TestCase
{
    private function validate(array $data): Validator
    {
        $translator = new Translator(new ArrayLoader(), 'en');
        $factory = new ValidationFactory($translator);

        return $factory->make($data, (new UpdateLoanSettingsRequest())->rules());
    }

    public function test_valid_reschedule_fee_flat_passes(): void
    {
        $v = $this->validate([
            'reschedule_fee_enabled' => true,
            'reschedule_fee_type' => 'flat',
            'reschedule_fee_amount' => 500,
            'reschedule_fee_collection' => 'savings',
        ]);
        $this->assertFalse($v->fails(), implode(', ', $v->errors()->all()));
    }

    public function test_invalid_fee_type_fails(): void
    {
        $v = $this->validate(['reschedule_fee_type' => 'fixed']);
        $this->assertTrue($v->fails());
        $this->assertTrue($v->errors()->has('reschedule_fee_type'));
    }

    public function test_invalid_collection_method_fails(): void
    {
        $v = $this->validate(['reschedule_fee_collection' => 'deduct']);
        $this->assertTrue($v->fails());
        $this->assertTrue($v->errors()->has('reschedule_fee_collection'));
    }

    public function test_invalid_basis_fails(): void
    {
        $v = $this->validate(['reschedule_fee_basis' => 'loan_amount']);
        $this->assertTrue($v->fails());
        $this->assertTrue($v->errors()->has('reschedule_fee_basis'));
    }

    public function test_valid_percentage_fee_with_basis_passes(): void
    {
        $v = $this->validate([
            'reschedule_product_change_fee_type' => 'percentage',
            'reschedule_product_change_fee_amount' => 2.5,
            'reschedule_product_change_fee_basis' => 'outstanding_balance',
        ]);
        $this->assertFalse($v->fails(), implode(', ', $v->errors()->all()));
    }

    public function test_negative_fee_amount_fails(): void
    {
        $v = $this->validate(['reschedule_fee_amount' => -10]);
        $this->assertTrue($v->fails());
        $this->assertTrue($v->errors()->has('reschedule_fee_amount'));
    }
}
