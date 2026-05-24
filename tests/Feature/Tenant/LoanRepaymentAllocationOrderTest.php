<?php

namespace Tests\Feature\Tenant;

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Modules\Settings\Models\LoanSetting;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

class LoanRepaymentAllocationOrderTest extends TenantTestCase
{
    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = Staff::create([
            'name' => 'Repayment Settings Admin',
            'email' => 'repayment-settings-admin@test.com',
            'password' => Hash::make('password'),
            'role' => 'Admin',
            'branch_id' => 1,
            'is_tenant_admin' => true,
        ]);

        $this->actingAs($this->staff, 'tenant');
    }

    /**
     * @dataProvider allocationOrderProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('allocationOrderProvider')]
    public function test_preview_uses_configured_branch_allocation_order(string $order, array $expected): void
    {
        LoanSetting::currentForBranch(1)->update([
            'repayment_allocation_order' => $order,
        ]);

        $loan = $this->createLoanWithSinglePendingSchedule(branchId: 1);

        $response = $this->postJson("/api/v1/tenant/loans/{$loan->id}/repayments/preview", [
            'amount' => 60,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.penalty', $expected['penalty'])
            ->assertJsonPath('data.charges', $expected['charges'])
            ->assertJsonPath('data.interest', $expected['interest'])
            ->assertJsonPath('data.principal', $expected['principal'])
            ->assertJsonPath('data.overpayment', 0)
            ->assertJsonPath('data.schedules.0.penalty_applied', $expected['penalty'])
            ->assertJsonPath('data.schedules.0.charges_applied', $expected['charges'])
            ->assertJsonPath('data.schedules.0.interest_applied', $expected['interest'])
            ->assertJsonPath('data.schedules.0.principal_applied', $expected['principal']);
    }

    public function test_preview_falls_back_to_default_order_when_branch_setting_missing(): void
    {
        $loan = $this->createLoanWithSinglePendingSchedule(branchId: 2);

        $response = $this->postJson("/api/v1/tenant/loans/{$loan->id}/repayments/preview", [
            'amount' => 60,
        ]);

        // Default: Penalties & Charges -> Interest -> Principal
        $response->assertOk()
            ->assertJsonPath('data.penalty', 10)
            ->assertJsonPath('data.charges', 20)
            ->assertJsonPath('data.interest', 30)
            ->assertJsonPath('data.principal', 0)
            ->assertJsonPath('data.overpayment', 0);
    }

    public static function allocationOrderProvider(): array
    {
        return [
            'principal -> interest -> penalties & charges' => [
                'principal_interest_penalties_charges',
                ['penalty' => 0, 'charges' => 0, 'interest' => 0, 'principal' => 60],
            ],
            'interest -> principal -> penalties & charges' => [
                'interest_principal_penalties_charges',
                ['penalty' => 0, 'charges' => 0, 'interest' => 30, 'principal' => 30],
            ],
            'penalties & charges -> interest -> principal' => [
                'penalties_charges_interest_principal',
                ['penalty' => 10, 'charges' => 20, 'interest' => 30, 'principal' => 0],
            ],
            'penalties & charges -> principal -> interest' => [
                'penalties_charges_principal_interest',
                ['penalty' => 10, 'charges' => 20, 'interest' => 0, 'principal' => 30],
            ],
        ];
    }

    private function createLoanWithSinglePendingSchedule(int $branchId): Loan
    {
        $member = Member::create([
            'name' => 'Member '.$branchId,
            'code' => 'member_'.$branchId.'_'.uniqid(),
            'email' => 'member_'.$branchId.'_'.uniqid().'@test.com',
            'password' => Hash::make('password'),
            'branch_id' => $branchId,
        ]);

        $loan = Loan::create([
            'loan_no' => 'LN-ALLOC-'.strtoupper(uniqid()),
            'member_id' => $member->id,
            'loan_product_id' => null,
            'principal' => 100,
            'interest_rate' => 10,
            'term_months' => 1,
            'disbursed_at' => now()->toDateString(),
            'status' => 'disbursed',
            'outstanding_balance' => 100,
            'branch_id' => $branchId,
        ]);

        LoanSchedule::create([
            'loan_id' => $loan->id,
            'schedule_id' => 'SCH-'.strtoupper(uniqid()),
            'due_date' => now()->toDateString(),
            'installment_no' => 1,
            'principal_due' => 100,
            'interest_due' => 30,
            'charges_due' => 20,
            'penalty_due' => 10,
            'total_due' => 160,
            'principal_paid' => 0,
            'interest_paid' => 0,
            'charges_paid' => 0,
            'penalty_paid' => 0,
            'outstanding_balance' => 160,
            'status' => 'pending',
        ]);

        return $loan;
    }
}
