<?php

namespace Tests\Feature\Tenant;

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class MemberRegistrationTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure we have a general savings product as expected by MemberController
        SavingsProduct::firstOrCreate(
            ['name' => 'General Savings Account'],
            [
                'code' => 'GSA01',
                'type' => 'standard',
                'interest_rate' => 0,
                'status' => 'active'
            ]
        );

        // Configure onboarding settings to auto-create savings account
        OnboardingSettings::current()->update([
            'auto_create_savings_account' => true,
            'require_member_approval' => false,
        ]);
    }

    public function test_member_registration_automatically_creates_savings_account(): void
    {
        $staff = Staff::factory()->create();
        $this->actingAs($staff, 'tenant');

        $memberData = [
            'member_type' => 'new_member',
            'name' => 'John Doe',
            'gender' => 'male',
            'phone' => '256700111222',
            'marital_status' => 'single',
            'nationality' => 'Ugandan',
            'address' => 'Kampala, Uganda',
            'initial_deposit' => 50000,
        ];

        $response = $this->postJson('/api/v1/tenant/members', $memberData);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Member registered successfully and savings account generated.');

        // Assert member was created
        $this->assertDatabaseHas('members', [
            'name' => 'John Doe',
            'phone' => '256700111222',
        ], 'tenant');

        $member = Member::withoutGlobalScopes()->where('phone', '256700111222')->first();
        $this->assertNotNull($member);

        // Assert savings account was created automatically
        $this->assertDatabaseHas('savings_accounts', [
            'member_id' => $member->id,
            'initial_deposit' => 50000,
            'status' => 'active',
        ], 'tenant');

        $account = SavingsAccount::withoutGlobalScopes()->where('member_id', $member->id)->first();
        $this->assertNotNull($account);
        $this->assertEquals(50000, $account->initial_deposit);
    }

    public function test_existing_member_registration_with_selected_product(): void
    {
        $staff = Staff::factory()->create();
        $this->actingAs($staff, 'tenant');

        $product = SavingsProduct::factory()->create([
            'name' => 'Special Savings',
            'status' => 'active'
        ]);

        $memberData = [
            'member_type' => 'existing_member',
            'name' => 'Jane Smith',
            'gender' => 'female',
            'phone' => '256700333444',
            'marital_status' => 'married',
            'nationality' => 'Ugandan',
            'address' => 'Entebbe, Uganda',
            'savings_product_id' => $product->id,
            'opening_balance' => 100000,
            'is_shareholder' => 'no',
        ];

        $response = $this->postJson('/api/v1/tenant/members', $memberData);

        $response->assertStatus(200);

        $member = Member::withoutGlobalScopes()->where('phone', '256700333444')->first();
        $this->assertNotNull($member);

        // Assert savings account was created with correct product and balance
        $this->assertDatabaseHas('savings_accounts', [
            'member_id' => $member->id,
            'savings_product_id' => $product->id,
            'initial_deposit' => 100000,
        ], 'tenant');
    }
}
