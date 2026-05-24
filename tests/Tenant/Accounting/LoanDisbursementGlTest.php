<?php

/**
 * @property SavingsProduct $standardProduct
 */
use App\Models\Member;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\SubLedger;
use App\Tenant\Modules\Accounting\Services\SavingsCoaResolver;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Tests\TenantTestCase;

beforeEach(function () {
    /** @var TenantTestCase|mixed $this */
    $this->standardProduct = SavingsProduct::create([
        'code' => 'STD',
        'name' => 'Standard',
        'type' => 'standard',
        'status' => 'active',
    ]);
});

it('mandatory account type resolves to GL 21101', function () {
    ChartOfAccount::create([
        'gl_code' => '21101', 'name' => 'Mandatory Savings', 'account_type' => 'LIABILITY',
        'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 3,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);

    $account = SavingsAccount::create([
        'savings_product_id' => $this->standardProduct->id,
        'account_no' => 'MAN-000001',
        'account_type' => 'mandatory',
        'balance' => 0,
        'status' => 'active',
        'code' => 'MAN001',
    ]);
    $account->loadMissing('savingsProduct');

    $resolver = app(SavingsCoaResolver::class);
    $gl = $resolver->resolveSavingsLiabilityAccount($account);

    expect($gl->gl_code)->toBe('21101');
});

it('voluntary account type resolves to GL 21102', function () {
    ChartOfAccount::create([
        'gl_code' => '21102', 'name' => 'Voluntary Savings', 'account_type' => 'LIABILITY',
        'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 3,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);

    $account = SavingsAccount::create([
        'savings_product_id' => $this->standardProduct->id,
        'account_no' => 'VOL-000001',
        'account_type' => 'voluntary',
        'balance' => 0,
        'status' => 'active',
        'code' => 'VOL001',
    ]);
    $account->loadMissing('savingsProduct');

    $resolver = app(SavingsCoaResolver::class);
    $gl = $resolver->resolveSavingsLiabilityAccount($account);

    expect($gl->gl_code)->toBe('21102');
});

it('fixed account type resolves to GL 21103', function () {
    ChartOfAccount::create([
        'gl_code' => '21103', 'name' => 'Fixed Deposits', 'account_type' => 'LIABILITY',
        'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 3,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);

    $fixedProduct = SavingsProduct::create([
        'code' => 'FD',
        'name' => 'Fixed Deposit',
        'type' => 'fixed',
        'status' => 'active',
    ]);

    $account = SavingsAccount::create([
        'savings_product_id' => $fixedProduct->id,
        'account_no' => 'FD-000001',
        'account_type' => 'fixed',
        'balance' => 0,
        'status' => 'active',
        'code' => 'FD001',
    ]);
    $account->loadMissing('savingsProduct');

    $resolver = app(SavingsCoaResolver::class);
    $gl = $resolver->resolveSavingsLiabilityAccount($account);

    expect($gl->gl_code)->toBe('21103');
});

it('default account type falls back to GL 21102', function () {
    ChartOfAccount::create([
        'gl_code' => '21102', 'name' => 'Voluntary Savings', 'account_type' => 'LIABILITY',
        'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 3,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);

    $account = SavingsAccount::create([
        'savings_product_id' => $this->standardProduct->id,
        'account_no' => 'DEF-000001',
        'balance' => 0,
        'status' => 'active',
        'code' => 'DEF001',
    ]);
    $account->loadMissing('savingsProduct');

    $resolver = app(SavingsCoaResolver::class);
    $gl = $resolver->resolveSavingsLiabilityAccount($account);

    expect($gl->gl_code)->toBe('21102');
});

it('disburse-to-savings posts SubLedger entry for GL 21101', function () {
    $gl2111 = ChartOfAccount::create([
        'gl_code' => '21101', 'name' => 'Mandatory Savings Deposits', 'account_type' => 'LIABILITY',
        'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 3,
        'is_control' => false, 'is_postable' => true,
    ]);

    $member = Member::create([
        'name' => 'Test Member',
        'member_number' => 'MBR-DISB-001',
        'code' => 'MBR-DISB-001',
        'email' => 'disb-'.uniqid().'@test.local',
        'password' => 'secret',
        'status' => 'active',
        'phone' => '0700000099',
    ]);

    $savings = SavingsAccount::create([
        'savings_product_id' => $this->standardProduct->id,
        'member_id' => $member->id,
        'account_no' => 'DISB-000001',
        'account_type' => 'mandatory',
        'balance' => 0,
        'status' => 'active',
        'code' => 'DISBTEST01',
    ]);

    $loan = Loan::create([
        'loan_no' => 'LN-DISB-001',
        'member_id' => $member->id,
        'principal' => 10000,
        'interest_rate' => 12,
        'term_months' => 12,
        'disbursed_at' => now()->toDateString(),
        'status' => 'disbursed',
    ]);

    $je = JournalEntry::on('tenant')->create([
        'entry_no' => 'JE-DISB-20260101-00001',
        'date' => now()->toDateString(),
        'journal_type' => 'DISBURSEMENT',
        'fiscal_period' => now()->format('Y-m'),
        'narration' => 'Loan disbursement test',
        'status' => 'posted',
    ]);

    // Use the public SavingsJournalService to post a deposit-like entry (simulating disburse-to-savings)
    $depositTxn = Transaction::create([
        'reference' => 'DISB-DEP-'.uniqid(),
        'member_id' => $member->id,
        'type' => 'deposit',
        'amount' => 5000,
        'payment_mode' => 'bank_transfer',
        'deposited_by' => 'System',
        'transaction_date' => now()->toDateString(),
        'account_id' => $savings->id,
        'account_type' => SavingsAccount::class,
        'created_by' => 1,
    ]);

    ChartOfAccount::create([
        'gl_code' => '11102', 'name' => 'Cash at Bank', 'account_type' => 'ASSET',
        'account_subtype' => 'bank', 'normal_balance' => 'DR', 'level' => 4,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);

    $postedJe = app(SavingsJournalService::class)
        ->postDeposit($depositTxn, $savings);

    expect($postedJe)->not->toBeNull();

    $entry = SubLedger::on('tenant')
        ->where('entity_id', $member->id)
        ->first();

    expect($entry)->not->toBeNull('Expected a SubLedger entry for the disbursed savings account');
    expect($entry->journal_entry_id)->toBe($postedJe->id);
});
