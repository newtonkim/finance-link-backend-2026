<?php

use App\Models\Staff;
use App\Tenant\Modules\Loans\Models\LoanProduct;

require_once __DIR__.'/guarantor_helpers.php';

/**
 * Guarantors can only be added once an application has been saved, so the create
 * wizard's eligibility check said "Eligible" and submission was then refused with
 * "This loan needs at least 1 guarantor(s)". The check now warns up front.
 */
beforeEach(function () {
    $this->staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->staff, 'sanctum');

    seedGuarantorSettings();
});

function checkEligibility(array $product = []): array
{
    $loanProduct = LoanProduct::create(['name' => 'Test Loan '.uniqid(), ...$product]);

    return test()->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson('/api/v1/tenant/loan-applications/eligibility-check', [
            'member_id' => memberWithSavings(0)->id,
            'loan_product_id' => $loanProduct->id,
            'requested_amount' => 1000,
            'requested_term' => 12,
        ])
        ->assertOk()
        ->json('data');
}

function guarantorWarning(array $data): ?array
{
    return collect($data['warnings'])->firstWhere('key', 'guarantors');
}

it('warns that guarantors must be added when the sacco requires them', function () {
    setGuarantorSetting('sacco-guarantor-required-on-loan-application', 1);
    setGuarantorSetting('sacco-guarantor-minimum-number', 1);

    $warning = guarantorWarning(checkEligibility());

    expect($warning)->not->toBeNull()
        ->and($warning['message'])->toContain('at least 1 guarantor');
});

it('uses the product\'s own minimum in the warning', function () {
    setGuarantorSetting('sacco-guarantor-required-on-loan-application', 0);

    $warning = guarantorWarning(checkEligibility(['min_guarantors' => 2]));

    expect($warning['message'])->toContain('at least 2 guarantor');
});

it('does not warn when guarantors are not required', function () {
    setGuarantorSetting('sacco-guarantor-required-on-loan-application', 0);

    expect(guarantorWarning(checkEligibility()))->toBeNull();
});

it('does not make the member ineligible because guarantors are still to come', function () {
    setGuarantorSetting('sacco-guarantor-required-on-loan-application', 1);
    setGuarantorSetting('sacco-guarantor-minimum-number', 1);

    $data = checkEligibility();

    expect(collect($data['failed'])->pluck('key'))->not->toContain('guarantors');
});
