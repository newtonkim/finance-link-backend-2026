<?php

namespace Tests\Feature\Tenant;

use App\Http\Requests\Tenant\StoreLoanProductRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TenantTestCase;

class StoreLoanProductRequestTest extends TenantTestCase
{
    private function validate(array $data)
    {
        return Validator::make($data, (new StoreLoanProductRequest)->rules());
    }

    public function test_minimal_legacy_payload_still_passes(): void
    {
        $validator = $this->validate([
            'name' => 'Legacy Loan',
            'is_active' => true,
        ]);

        $this->assertFalse($validator->fails(), implode(', ', $validator->errors()->all()));
    }

    public function test_invalid_repayment_structure_fails(): void
    {
        $validator = $this->validate([
            'name' => 'Structured Loan',
            'is_active' => false,
            'repayment_structure' => 'broken',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('repayment_structure'));
    }
}
