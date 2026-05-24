<?php

namespace Tests\Feature\Tenant;

use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Loans\Models\LoanCharge;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

class LoanChargeApiTest extends TenantTestCase
{
    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = Staff::create([
            'name' => 'Loan Charge Admin',
            'email' => 'loanchargeadmin@test.com',
            'password' => Hash::make('password'),
            'role' => 'Admin',
            'is_tenant_admin' => true,
        ]);
    }

    public function test_can_create_loan_charge(): void
    {
        $income = $this->createAccount('INCOME', '4010', 'Loan Processing Fees', 'CR');
        $recv = $this->createAccount('ASSET', '1157', 'Charges Receivable', 'DR');

        $payload = [
            'name' => 'Processing Fee',
            'category' => 'processing_fee',
            'charge_type' => 'percentage',
            'value' => 2,
            'frequency' => 'one_time',
            'grace_days' => 0,
            'max_value_type' => 'none',
            'is_active' => true,
            'income_account_id' => $income->id,
            'receivable_account_id' => $recv->id,
            'description' => '2% disbursement fee',
        ];

        $response = $this->actingAs($this->staff, 'tenant')
            ->postJson('/api/v1/tenant/loan-charges', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Processing Fee')
            ->assertJsonPath('data.category', 'processing_fee')
            ->assertJsonPath('data.charge_type', 'percentage');

        $this->assertDatabaseHas('loan_charges', [
            'name' => 'Processing Fee',
            'category' => 'processing_fee',
        ]);
    }

    public function test_validates_percentage_value_limit(): void
    {
        $response = $this->actingAs($this->staff, 'tenant')
            ->postJson('/api/v1/tenant/loan-charges', [
                'name' => 'Invalid Percentage Charge',
                'category' => 'penalty',
                'charge_type' => 'percentage',
                'value' => 120,
                'frequency' => 'daily',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['value']);
    }

    public function test_can_toggle_loan_charge(): void
    {
        $charge = LoanCharge::create([
            'name' => 'Late Fee',
            'category' => 'late_fee',
            'charge_type' => 'flat',
            'value' => 500,
            'frequency' => 'daily',
            'grace_days' => 3,
            'max_value_type' => 'none',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->patchJson("/api/v1/tenant/loan-charges/{$charge->id}/toggle");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_cannot_delete_charge_assigned_to_product(): void
    {
        $charge = LoanCharge::create([
            'name' => 'Appraisal Fee',
            'category' => 'appraisal_fee',
            'charge_type' => 'flat',
            'value' => 200,
            'frequency' => 'one_time',
            'grace_days' => 0,
            'max_value_type' => 'none',
            'is_active' => true,
        ]);

        $product = LoanProduct::create([
            'name' => 'Emergency Loan',
            'is_active' => true,
        ]);
        $product->charges()->sync([$charge->id]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->deleteJson("/api/v1/tenant/loan-charges/{$charge->id}");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot delete charge that is assigned to one or more loan products. Remove it from products first.');
    }

    private function createAccount(string $type, string $glCode, string $name, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::create([
            'gl_code' => $glCode,
            'name' => $name,
            'account_subtype' => null,
            'account_type' => $type,
            'normal_balance' => $normalBalance,
            'level' => 1,
            'parent_id' => null,
            'is_control' => false,
            'is_postable' => true,
            'is_active' => true,
            'allow_manual' => true,
            'ifrs_category' => null,
            'sort_order' => 1,
        ]);
    }
}
