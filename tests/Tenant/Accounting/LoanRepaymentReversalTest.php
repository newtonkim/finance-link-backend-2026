<?php

namespace Tests\Tenant\Accounting;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Modules\Loans\Models\LoanTransaction;
use App\Tenant\Modules\Loans\Services\LoanRepaymentReversalService;

use Illuminate\Validation\ValidationException;
use Tests\TenantTestCase;

class LoanRepaymentReversalTest extends TenantTestCase
{
    public function test_cannot_reverse_already_reversed_transaction(): void
    {
        // Build minimal in-memory objects — no DB persistence needed for the guard test.
        $loan = new Loan(['loan_no' => 'LN-0001', 'outstanding_balance' => 10000]);
        $loan->id = 999;

        $txn = new LoanTransaction([
            'reversal_flag' => true,
            'amount_paid' => 1000,
            'principal_portion' => 800,
            'interest_portion' => 150,
            'penalty_portion' => 30,
            'charges_portion' => 20,
        ]);

        $service = app(LoanRepaymentReversalService::class);

        $threw = false;
        try {
            $service->reverse($loan, $txn, actorId: 1);
        } catch (ValidationException $e) {
            $threw = true;
            $this->assertArrayHasKey('transaction', $e->errors());
        }

        $this->assertTrue($threw, 'Expected a ValidationException for an already-reversed transaction.');
    }

    public function test_reverse_updates_schedule_balance_and_posts_synthetic_reversal_entry(): void
    {
        $cashAccount = ChartOfAccount::create([
            'gl_code' => '1111',
            'name' => 'Cash',
            'account_type' => 'ASSET',
            'account_subtype' => 'cash',
            'normal_balance' => 'DR',
            'level' => 4,
            'is_control' => false,
            'is_postable' => true,
            'is_active' => true,
        ]);
        $portfolioAccount = ChartOfAccount::create([
            'gl_code' => '1131',
            'name' => 'Loan Portfolio',
            'account_type' => 'ASSET',
            'account_subtype' => 'loan',
            'normal_balance' => 'DR',
            'level' => 4,
            'is_control' => false,
            'is_postable' => true,
            'is_active' => true,
        ]);
        $interestIncomeAccount = ChartOfAccount::create([
            'gl_code' => '4111',
            'name' => 'Interest Income',
            'account_type' => 'INCOME',
            'account_subtype' => 'interest_income',
            'normal_balance' => 'CR',
            'level' => 4,
            'is_control' => false,
            'is_postable' => true,
            'is_active' => true,
        ]);

        $member = Member::create([
            'name' => 'Repayment Reversal Member',
            'member_number' => 'RR-001',
            'code' => 'RR001',
            'email' => 'rep-rev-'.uniqid().'@test.local',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);

        $product = LoanProduct::create([
            'code' => 'REV01',
            'name' => 'Repayment Reversal Product',
            'interest_rate' => 12,
            'interest_method' => 'reducing_balance',
            'repayment_structure' => 'equal_installment',
            'repayment_cycle' => 'monthly',
            'interest_period' => 'monthly',
            'loan_duration' => 12,
            'duration_type' => 'months',
            'is_active' => true,
            'loan_portfolio_account_id' => $portfolioAccount->id,
            'interest_income_account_id' => $interestIncomeAccount->id,
            'interest_receivable_account_id' => $interestIncomeAccount->id,
            'disbursement_account_id' => $cashAccount->id,
        ]);

        $loan = Loan::create([
            'loan_no' => 'LN-REV-'.uniqid(),
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
        ]);

        $schedule = LoanSchedule::create([
            'loan_id' => $loan->id,
            'installment_no' => 1,
            'due_date' => now()->subDay(),
            'principal_due' => 200.00,
            'interest_due' => 50.00,
            'charges_due' => 0.00,
            'penalty_due' => 0.00,
            'total_due' => 250.00,
            'principal_paid' => 200.00,
            'interest_paid' => 50.00,
            'charges_paid' => 0.00,
            'penalty_paid' => 0.00,
            'status' => 'paid',
        ]);

        $repayment = LoanTransaction::create([
            'loan_id' => $loan->id,
            'member_id' => $member->id,
            'amount_paid' => 250.00,
            'principal_portion' => 200.00,
            'interest_portion' => 50.00,
            'penalty_portion' => 0.00,
            'charges_portion' => 0.00,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'reversal_flag' => false,
        ]);

        app(LoanRepaymentReversalService::class)->reverse($loan, $repayment, actorId: 1);

        $this->assertTrue((bool) $repayment->fresh()->reversal_flag);
        $this->assertSame(1, (int) $repayment->fresh()->reversed_by);
        $this->assertNotNull($repayment->fresh()->reversed_date);
        $this->assertEquals('1200.00', $loan->fresh()->outstanding_balance);

        $freshSchedule = $schedule->fresh();
        $this->assertEquals('0.00', $freshSchedule->principal_paid);
        $this->assertEquals('0.00', $freshSchedule->interest_paid);
        $this->assertSame('pending', $freshSchedule->status);

        $reversalJe = JournalEntry::query()
            ->where('reference', $loan->loan_no)
            ->where('journal_type', 'loan')
            ->where('narration', 'like', 'Repayment reversal (synthetic)%')
            ->latest('id')
            ->first();

        $this->assertNotNull($reversalJe);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $reversalJe->id,
            'account_id' => $cashAccount->id,
            'debit' => 0.00,
            'credit' => 250.00,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $reversalJe->id,
            'account_id' => $portfolioAccount->id,
            'debit' => 200.00,
            'credit' => 0.00,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $reversalJe->id,
            'account_id' => $interestIncomeAccount->id,
            'debit' => 50.00,
            'credit' => 0.00,
        ]);
    }
}
