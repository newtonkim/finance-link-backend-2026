<?php

namespace Tests\Tenant\Accounting;

use App\Models\Member;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Services\LoanRescheduleService;
use Illuminate\Validation\ValidationException;

use Tests\TenantTestCase;

class LoanRescheduleAccountingTest extends TenantTestCase
{
    public function test_validate_eligibility_rejects_when_no_installment_has_been_paid(): void
    {
        $member = Member::create([
            'name' => 'Reschedule Member',
            'member_number' => 'RS-001',
            'code' => 'RS001',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);

        $product = LoanProduct::create([
            'code' => 'RSC01',
            'name' => 'Reschedule Product',
            'interest_rate' => 12,
            'interest_method' => 'flat',
            'repayment_structure' => 'equal_installment',
            'repayment_cycle' => 'monthly',
            'interest_period' => 'monthly',
            'loan_duration' => 12,
            'duration_type' => 'months',
            'is_active' => true,
            'allow_reschedule' => true,
        ]);

        $loan = Loan::create([
            'loan_no' => 'LN-RSC-'.uniqid(),
            'member_id' => $member->id,
            'loan_product_id' => $product->id,
            'principal' => 1000.00,
            'outstanding_balance' => 1000.00,
            'status' => 'disbursed',
            'interest_rate' => 12,
            'term_months' => 12,
            'disbursed_at' => now(),
            'schedule_date' => now(),
            'branch_id' => 1,
            'reschedule_count' => 0,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('At least one installment must be paid before rescheduling');

        app(LoanRescheduleService::class)->validateEligibility($loan);
    }
}
