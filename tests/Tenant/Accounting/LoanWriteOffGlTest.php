<?php

namespace Tests\Tenant\Accounting;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Services\LoanWriteOffService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TenantTestCase;

class LoanWriteOffGlTest extends TenantTestCase
{
    private ChartOfAccount $portfolioAccount;

    private ChartOfAccount $writeOffAccount;

    private LoanProduct $loanProduct;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->portfolioAccount = ChartOfAccount::create([
            'gl_code' => '1131', 'name' => 'Personal Loans', 'account_type' => 'ASSET',
            'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4,
            'is_control' => false, 'is_postable' => true, 'is_active' => true,
        ]);

        $this->writeOffAccount = ChartOfAccount::create([
            'gl_code' => '51304', 'name' => 'Loan Write-off Expense', 'account_type' => 'EXPENSE',
            'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 4,
            'is_control' => false, 'is_postable' => true, 'is_active' => true,
        ]);

        $this->loanProduct = LoanProduct::create([
            'code' => 'WO01',
            'name' => 'Write-off Test Product',
            'interest_rate' => 12,
            'interest_method' => 'reducing_balance',
            'repayment_structure' => 'equal_installment',
            'repayment_cycle' => 'monthly',
            'interest_period' => 'monthly',
            'loan_duration' => 12,
            'duration_type' => 'months',
            'is_active' => true,
            'loan_portfolio_account_id' => $this->portfolioAccount->id,
        ]);

        $this->member = Member::create([
            'name' => 'Write-off Member',
            'member_number' => 'WO-001',
            'code' => 'WO001',
            'email' => 'writeoff-'.uniqid().'@test.local',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);
    }

    private function makeLoan(LoanStatus $status = LoanStatus::Arrears, float $outstanding = 1500.00): Loan
    {
        return Loan::create([
            'loan_no' => 'WO-LN-'.uniqid(),
            'member_id' => $this->member->id,
            'loan_product_id' => $this->loanProduct->id,
            'principal' => $outstanding,
            'outstanding_balance' => $outstanding,
            'status' => $status,
            'interest_rate' => 12,
            'term_months' => 12,
            'disbursed_at' => Carbon::now(),
            'schedule_date' => Carbon::now(),
            'branch_id' => 1,
        ]);
    }

    public function test_write_off_posts_debit_to_gl_5134(): void
    {
        $loan = $this->makeLoan(outstanding: 1500.00);

        App::make(LoanWriteOffService::class)->writeOff($loan, actorId: 1, narration: 'Unrecoverable');

        $debitLine = JournalEntryLine::where('loan_id', $loan->id)
            ->where('account_id', $this->writeOffAccount->id)
            ->where('debit', 1500.00)
            ->where('credit', 0)
            ->first();

        $this->assertNotNull($debitLine, 'Expected DR 51304 for 1500.00 in write-off JE.');
    }

    public function test_write_off_posts_credit_to_loan_portfolio_account(): void
    {
        $loan = $this->makeLoan(outstanding: 1500.00);

        App::make(LoanWriteOffService::class)->writeOff($loan, actorId: 1, narration: 'Unrecoverable');

        $creditLine = JournalEntryLine::where('loan_id', $loan->id)
            ->where('account_id', $this->portfolioAccount->id)
            ->where('credit', 1500.00)
            ->where('debit', 0)
            ->first();

        $this->assertNotNull($creditLine, 'Expected CR loan portfolio account for 1500.00 in write-off JE.');
    }

    public function test_write_off_transitions_loan_status_to_written_off(): void
    {
        $loan = $this->makeLoan();

        App::make(LoanWriteOffService::class)->writeOff($loan, actorId: 1, narration: 'Unrecoverable');

        $this->assertSame(LoanStatus::WrittenOff->value, $loan->fresh()->status->value);
    }

    public function test_write_off_rejects_closed_loan(): void
    {
        $loan = $this->makeLoan(status: LoanStatus::Closed);

        $this->expectException(ValidationException::class);

        App::make(LoanWriteOffService::class)->writeOff($loan, actorId: 1, narration: 'Should fail');
    }
}
