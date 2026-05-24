<?php

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Models\SubLedger;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsInterestPosting;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Services\RegularSavingsInterestService;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use Illuminate\Support\Facades\DB;

// ── Setup ────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->expenseAccount = ChartOfAccount::create([
        'gl_code' => '5200',
        'name' => 'Savings Interest Expense',
        'account_type' => 'EXPENSE',
        'account_subtype' => 'Interest Expense',
        'normal_balance' => 'DR',
        'level' => 3,
        'is_control' => false,
        'is_postable' => true,
    ]);

    $this->payableAccount = ChartOfAccount::create([
        'gl_code' => '2120',
        'name' => 'Savings Interest Payable',
        'account_type' => 'LIABILITY',
        'account_subtype' => 'Payables',
        'normal_balance' => 'CR',
        'level' => 3,
        'is_control' => false,
        'is_postable' => true,
    ]);

    $this->product = SavingsProduct::create([
        'code' => 'SAV01',
        'name' => 'Standard Savings',
        'type' => 'standard',
        'status' => 'active',
        'interest_rate' => 0.1200,
        'interest_enabled' => true,
        'interest_expense_account_id' => $this->expenseAccount->id,
        'interest_payable_account_id' => $this->payableAccount->id,
    ]);

    OnboardingSettings::current()->update(['regular_savings_interest_enabled' => true]);

    $this->service = app(RegularSavingsInterestService::class);
});

// ── Tests ────────────────────────────────────────────────────────────────────

it('posts interest when product interest is enabled', function () {
    $account = SavingsAccount::create([
        'savings_product_id' => $this->product->id,
        'account_no' => 'SAV-001',
        'code' => 'SAV001',
        'account_type' => 'mandatory',
        'balance' => 10000.00,
        'status' => 'active',
    ]);

    // Backdate account so the interest period is > 0 days
    DB::table('savings_accounts')
        ->where('id', $account->id)
        ->update(['created_at' => now()->subDays(30)]);
    $account->refresh();

    $this->service->processAccount($account, 1);

    // SavingsInterestPosting created
    $posting = SavingsInterestPosting::on('tenant')
        ->where('savings_account_id', $account->id)
        ->first();

    expect($posting)->not->toBeNull('Expected a SavingsInterestPosting to be created');
    expect((float) $posting->interest_amount)->toBeGreaterThan(0);

    // JournalEntry created and balanced
    expect($posting->journal_entry_id)->not->toBeNull();

    $je = JournalEntry::on('tenant')->find($posting->journal_entry_id);
    expect($je)->not->toBeNull();

    $lines = JournalEntryLine::on('tenant')->where('journal_entry_id', $je->id)->get();
    $totalDebit = $lines->sum('debit');
    $totalCredit = $lines->sum('credit');

    expect(number_format((float) $totalDebit, 2))
        ->toBe(number_format((float) $totalCredit, 2));

    // SubLedger entries present
    $subLedgerEntries = SubLedger::on('tenant')
        ->where('journal_entry_id', $je->id)
        ->get();
    expect($subLedgerEntries->count())->toBeGreaterThan(0);

    // Account balance incremented
    $account->refresh();
    expect((float) $account->balance)->toBeGreaterThan(10000.00);

    // last_interest_posted_at updated
    expect($account->last_interest_posted_at)->not->toBeNull();
});

it('skips account when global setting is disabled', function () {
    OnboardingSettings::current()->update(['regular_savings_interest_enabled' => false]);

    SavingsAccount::create([
        'savings_product_id' => $this->product->id,
        'account_no' => 'SAV-002',
        'code' => 'SAV002',
        'account_type' => 'voluntary',
        'balance' => 5000.00,
        'status' => 'active',
    ]);

    $summary = $this->service->runBatchPosting(1);

    expect($summary['posted'])->toBe(0);
    expect(SavingsInterestPosting::on('tenant')->count())->toBe(0);
});

it('skips account when product interest is disabled', function () {
    $this->product->update(['interest_enabled' => false]);

    SavingsAccount::create([
        'savings_product_id' => $this->product->id,
        'account_no' => 'SAV-003',
        'code' => 'SAV003',
        'account_type' => 'voluntary',
        'balance' => 5000.00,
        'status' => 'active',
    ]);

    $summary = $this->service->runBatchPosting(1);

    expect($summary['posted'])->toBe(0);
    expect(SavingsInterestPosting::on('tenant')->count())->toBe(0);
});

it('skips fixed deposit accounts', function () {
    $fdProduct = SavingsProduct::create([
        'code' => 'FD01',
        'name' => 'Fixed Deposit',
        'type' => 'fixed',
        'status' => 'active',
    ]);

    $fdAccount = SavingsAccount::create([
        'savings_product_id' => $fdProduct->id,
        'account_no' => 'FD-001',
        'code' => 'FD001',
        'account_type' => 'fixed',
        'balance' => 10000.00,
        'status' => 'active',
    ]);

    $this->service->processAccount($fdAccount, 1);

    expect(SavingsInterestPosting::on('tenant')->count())->toBe(0);
});

it('does not double-post the same period', function () {
    $account = SavingsAccount::create([
        'savings_product_id' => $this->product->id,
        'account_no' => 'SAV-005',
        'code' => 'SAV005',
        'account_type' => 'mandatory',
        'balance' => 10000.00,
        'status' => 'active',
    ]);

    DB::table('savings_accounts')
        ->where('id', $account->id)
        ->update(['created_at' => now()->subDays(30)]);
    $account->refresh();

    $this->service->processAccount($account, 1);
    $this->service->processAccount($account, 1);

    expect(
        SavingsInterestPosting::on('tenant')
            ->where('savings_account_id', $account->id)
            ->count()
    )->toBe(1);
});
