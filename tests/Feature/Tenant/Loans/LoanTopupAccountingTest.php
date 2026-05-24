<?php

namespace Tests\Feature\Tenant\Loans;

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Services\LoanTopupService;
use Tests\TenantTestCase;

class LoanTopupAccountingTest extends TenantTestCase
{
    public function test_express_topup_posts_disbursement_journal_entry(): void
    {
        // Arrange: GL accounts
        $portfolioGl = ChartOfAccount::create([
            'gl_code' => '1310', 'name' => 'Loan Portfolio', 'account_type' => 'ASSET',
            'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        $disbGl = ChartOfAccount::create([
            'gl_code' => '1121', 'name' => 'Disbursement Account', 'account_type' => 'ASSET',
            'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        $interestIncomeGl = ChartOfAccount::create([
            'gl_code' => '4110', 'name' => 'Interest Income', 'account_type' => 'INCOME',
            'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        $interestReceivableGl = ChartOfAccount::create([
            'gl_code' => '1320', 'name' => 'Interest Receivable', 'account_type' => 'ASSET',
            'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        $penaltyIncomeGl = ChartOfAccount::create([
            'gl_code' => '4130', 'name' => 'Penalty Income', 'account_type' => 'INCOME',
            'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        $penaltyReceivableGl = ChartOfAccount::create([
            'gl_code' => '1330', 'name' => 'Penalty Receivable', 'account_type' => 'ASSET',
            'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);

        // Member (no MemberFactory exists — create directly)
        $member = Member::create([
            'name' => 'Test Member',
            'member_number' => 'MBR-TEST-001',
            'code' => 'MBR-TEST-001',
            'email' => 'testmember@test.com',
            'password' => bcrypt('password'),
            'status' => 'active',
            'phone' => '0700000000',
        ]);

        // Staff
        $staff = Staff::factory()->create();

        // Loan product with required GL mappings (no LoanProductFactory — create directly)
        $product = LoanProduct::create([
            'code' => 'TP01',
            'name' => 'Test Product',
            'interest_rate' => 12,
            'interest_method' => 'reducing_balance',
            'repayment_structure' => 'equal_installment',
            'repayment_cycle' => 'monthly',
            'interest_period' => 'monthly',
            'loan_duration' => 12,
            'duration_type' => 'months',
            'is_active' => true,
            'topup_auto_disbursement' => true,
            'loan_portfolio_account_id' => $portfolioGl->id,
            'disbursement_account_id' => $disbGl->id,
            'interest_income_account_id' => $interestIncomeGl->id,
            'interest_receivable_account_id' => $interestReceivableGl->id,
            'penalty_income_account_id' => $penaltyIncomeGl->id,
            'penalty_receivable_account_id' => $penaltyReceivableGl->id,
        ]);

        // Existing loan to topup (no LoanFactory — create directly)
        $existingLoan = Loan::create([
            'loan_no' => 'LN-TEST-001',
            'member_id' => $member->id,
            'loan_product_id' => $product->id,
            'principal' => 50000,
            'outstanding_balance' => 30000,
            'status' => LoanStatus::Active,
            'interest_rate' => 12,
            'term_months' => 12,
            'disbursed_at' => now(),
            'schedule_date' => now(),
            'branch_id' => 1,
        ]);

        $jeCountBefore = JournalEntry::on('tenant')->count();

        // Act
        $service = app(LoanTopupService::class);
        $result = $service->execute(
            referenceLoan: $existingLoan,
            freshCashAmount: 20000,
            requestedTerm: 12,
            topupType: 'consolidated',
            staffId: $staff->id,
        );

        // Assert: a disbursement JE was posted for the new loan
        $this->assertGreaterThan($jeCountBefore, JournalEntry::on('tenant')->count());

        $newLoan = Loan::on('tenant')->find($result['new_loan_id']);
        $this->assertNotNull($newLoan);

        $disbJe = JournalEntry::on('tenant')
            ->where('reference', $newLoan->loan_no)
            ->where('reference_type', 'loan')
            ->whereRaw("entry_no LIKE 'JE-LOAN_DISB%'")
            ->first();

        $this->assertNotNull($disbJe, 'Expected a LOAN_DISB journal entry for the new topup loan');

        $lines = $disbJe->lines()->get();
        $this->assertCount(2, $lines);

        $drLine = $lines->firstWhere('account_id', $portfolioGl->id);
        $crLine = $lines->firstWhere('account_id', $disbGl->id);

        $this->assertNotNull($drLine);
        $this->assertNotNull($crLine);
        $this->assertEquals((float) $newLoan->principal, (float) $drLine->debit);
        $this->assertEquals((float) $newLoan->principal, (float) $crLine->credit);
    }
}
