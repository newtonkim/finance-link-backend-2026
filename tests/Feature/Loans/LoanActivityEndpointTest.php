<?php

namespace Tests\Feature\Loans;

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Loans\Models\Loan;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

/**
 * Feature tests for GET /api/v1/tenant/loans/{id}/activities
 */
class LoanActivityEndpointTest extends TenantTestCase
{
    private Staff $staff;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = Staff::create([
            'name' => 'Activities Staff',
            'email' => 'activitiesstaff@test.com',
            'password' => Hash::make('password'),
            'role' => 'Admin',
            'is_tenant_admin' => true,
        ]);

        $this->member = Member::create([
            'name' => 'Endpoint Test Member',
            'code' => 'MBR-EP-'.uniqid(),
            'branch_id' => 1,
            'password' => Hash::make('password'),
        ]);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    /**
     * Create a minimal disbursed Loan row for endpoint tests.
     */
    private function makeLoan(array $overrides = []): Loan
    {
        return Loan::create(array_merge([
            'loan_no' => 'LN-EP-'.uniqid(),
            'member_id' => $this->member->id,
            'loan_product_id' => null,
            'principal' => 50000.00,
            'net_disbursed_amount' => 48000.00,
            'interest_rate' => 12.00,
            'term_months' => 12,
            'disbursed_at' => now()->subMonths(3)->toDateString(),
            'disbursement_method' => 'bank_transfer',
            'status' => 'disbursed',
            'outstanding_balance' => 48000.00,
            'branch_id' => 1,
        ], $overrides));
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * An authenticated request for a valid loan returns 200 with a
     * top-level 'data' array key.
     */
    public function test_authenticated_request_returns_200_with_data_array(): void
    {
        $loan = $this->makeLoan();

        $response = $this->actingAs($this->staff, 'tenant')
            ->getJson("/api/v1/tenant/loans/{$loan->id}/activities");

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);

        $this->assertIsArray($response->json('data'));
    }

    /**
     * An unauthenticated request returns 401 Unauthorized.
     */
    public function test_unauthenticated_request_returns_401(): void
    {
        $loan = $this->makeLoan();

        $response = $this->getJson("/api/v1/tenant/loans/{$loan->id}/activities");

        $response->assertStatus(401);
    }

    /**
     * Requesting activities for a loan ID that does not exist returns 404.
     */
    public function test_non_existent_loan_returns_404(): void
    {
        $response = $this->actingAs($this->staff, 'tenant')
            ->getJson('/api/v1/tenant/loans/999999/activities');

        $response->assertStatus(404);
    }
}
