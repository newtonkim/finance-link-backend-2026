<?php

namespace Tests\Unit;

use App\Http\Requests\Tenant\UpdateLoanSettingsRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\TestCase;

class UpdateLoanSettingsRequestTest extends TestCase
{
    private function makeValidator(array $data): Validator
    {
        $request = new UpdateLoanSettingsRequest();
        $translator = new Translator(new ArrayLoader(), 'en');
        $factory = new ValidationFactory($translator);

        return $factory->make($data, $request->rules());
    }

    public function test_authorize_returns_true(): void
    {
        $this->assertTrue((new UpdateLoanSettingsRequest())->authorize());
    }

    public function test_valid_repayment_allocation_order_passes_validation(): void
    {
        $validator = $this->makeValidator([
            'repayment_allocation_order' => 'interest_principal_penalties_charges',
        ]);

        $this->assertFalse($validator->fails(), implode(', ', $validator->errors()->all()));
    }

    public function test_invalid_repayment_allocation_order_fails_validation(): void
    {
        $validator = $this->makeValidator([
            'repayment_allocation_order' => 'principal_penalties_interest_charges',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('repayment_allocation_order'));
    }

    public function test_holiday_schedule_flags_accept_boolean_values(): void
    {
        $validator = $this->makeValidator([
            'push_installments_on_holidays' => true,
            'push_installments_on_holidays_weekdays_only' => false,
            'relative_scheduling' => true,
        ]);

        $this->assertFalse($validator->fails(), implode(', ', $validator->errors()->all()));
    }

    public function test_holiday_schedule_flags_reject_non_boolean_values(): void
    {
        $validator = $this->makeValidator([
            'push_installments_on_holidays_weekdays_only' => 'yes',
            'relative_scheduling' => 'maybe',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('push_installments_on_holidays_weekdays_only'));
        $this->assertTrue($validator->errors()->has('relative_scheduling'));
    }
}
