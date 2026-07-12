<?php

use App\Models\Member;
use App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Services\SavingsAccountStatementService;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Support\Carbon;

it('binds interface to concrete service', function () {
    expect(app(SavingsAccountStatementServiceInterface::class))
        ->toBeInstanceOf(SavingsAccountStatementService::class);
});

it('computes opening balance from pre-period transactions only', function () {
    $account = SavingsAccount::factory()->create(['balance' => 0]);
    $member = $account->member;

    // Two pre-period deposits
    Transaction::create([
        'reference' => 'T1',
        'member_id' => $member->id,
        'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'type' => 'deposit',
        'amount' => 1000,
        'charge_amount' => 0,
        'transaction_date' => Carbon::parse('2026-01-05'),
    ]);
    Transaction::create([
        'reference' => 'T2',
        'member_id' => $member->id,
        'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'type' => 'deposit',
        'amount' => 500,
        'charge_amount' => 0,
        'transaction_date' => Carbon::parse('2026-01-20'),
    ]);
    // Pre-period charge
    Transaction::create([
        'reference' => 'T3',
        'member_id' => $member->id,
        'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'type' => 'charge',
        'amount' => 50,
        'charge_amount' => 0,
        'transaction_date' => Carbon::parse('2026-01-25'),
    ]);
    // In-period row that must NOT count toward opening
    Transaction::create([
        'reference' => 'T4',
        'member_id' => $member->id,
        'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'type' => 'deposit',
        'amount' => 9999,
        'charge_amount' => 0,
        'transaction_date' => Carbon::parse('2026-02-15'),
    ]);

    $result = app(SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($result['balances']['opening'])->toBe(1450.0);
});

it('annotates period rows with credit/debit/running_balance and reconciles totals', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;

    // Pre-period: opening = 100
    Transaction::create([
        'reference' => 'A', 'member_id' => $member->id, 'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'type' => 'deposit', 'amount' => 100,
        'transaction_date' => Carbon::parse('2026-01-31'),
    ]);
    // Period: +500 deposit, +50 charge (debit), -200 withdrawal, +6000 deposit-charge (debit, charge_amount used because amount=0)
    Transaction::create([
        'reference' => 'B', 'member_id' => $member->id, 'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'type' => 'deposit', 'amount' => 500,
        'transaction_date' => Carbon::parse('2026-02-01'),
    ]);
    Transaction::create([
        'reference' => 'C', 'member_id' => $member->id, 'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'type' => 'charge', 'amount' => 50,
        'transaction_date' => Carbon::parse('2026-02-02'),
    ]);
    Transaction::create([
        'reference' => 'D', 'member_id' => $member->id, 'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'type' => 'withdrawal', 'amount' => 200,
        'transaction_date' => Carbon::parse('2026-02-03'),
    ]);
    Transaction::create([
        'reference' => 'E', 'member_id' => $member->id, 'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'type' => 'deposit-charge',
        'amount' => 0, 'charge_amount' => 6000,
        'transaction_date' => Carbon::parse('2026-02-04'),
    ]);

    $r = app(SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['balances']['opening'])->toBe(100.00);
    expect($r['balances']['total_credit'])->toBe(500.00);
    expect($r['balances']['total_debit'])->toBe(6250.00); // 50 + 200 + 6000
    expect($r['balances']['closing'])->toBe(-5650.00);    // 100 + 500 - 6250
    expect($r['balances']['count'])->toBe(4);

    // Running balance reconciles
    $last = end($r['transactions']);
    expect($last['running_balance'])->toBe(-5650.00);

    // First period row is the deposit (credit), last is the deposit-charge (debit using charge_amount)
    $deposit = $r['transactions'][0];
    expect($deposit['credit'])->toBe(500.00)->and($deposit['debit'])->toBe(0.0);
    $depositCharge = end($r['transactions']);
    expect($depositCharge['debit'])->toBe(6000.00); // charge_amount used because amount=0
});

it('excludes soft-deleted transactions', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;
    $row = Transaction::create([
        'reference' => 'X', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => SavingsAccount::class,
        'type' => 'deposit', 'amount' => 999,
        'transaction_date' => Carbon::parse('2026-02-10'),
    ]);
    $row->delete(); // soft-delete

    $r = app(SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['balances']['count'])->toBe(0);
    expect($r['balances']['total_credit'])->toBe(0.0);
    expect($r['transactions'])->toBeEmpty();
});

it('excludes reversed originals and includes the reversal entry', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;

    $original = Transaction::create([
        'reference' => 'ORIG', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => SavingsAccount::class,
        'type' => 'deposit', 'amount' => 1000,
        'transaction_date' => Carbon::parse('2026-02-05'),
        'is_reversed' => true,  // marked as reversed
    ]);
    Transaction::create([
        'reference' => 'REV', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => SavingsAccount::class,
        'type' => 'withdrawal', 'amount' => 1000,
        'transaction_date' => Carbon::parse('2026-02-06'),
        'reversal_of' => $original->id,  // is the reversal entry
        'is_reversed' => false,
    ]);

    $r = app(SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['balances']['count'])->toBe(1);                  // only the reversal entry
    expect($r['transactions'][0]['is_reversal'])->toBeTrue();
    expect($r['transactions'][0]['debit'])->toBe(1000.00);
    expect($r['transactions'][0]['credit'])->toBe(0.0);
    expect($r['balances']['total_debit'])->toBe(1000.00);
    expect($r['balances']['total_credit'])->toBe(0.0);
});

it('warns about unknown transaction types and excludes them from totals', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;
    Transaction::create([
        'reference' => 'U', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => SavingsAccount::class,
        'type' => 'mystery_type', 'amount' => 777,
        'transaction_date' => Carbon::parse('2026-02-15'),
    ]);

    $r = app(SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['warnings'])->toHaveCount(1);
    expect($r['warnings'][0])->toContain('mystery_type');
    expect($r['balances']['count'])->toBe(0);
});

it('populates account, member, and branch metadata', function () {
    $branch = DB::table('branches')->insertGetId(['name' => 'Main branch', 'created_at' => now(), 'updated_at' => now()]);
    $member = Member::factory()->create([
        'name' => 'Maya Nyamu', 'member_number' => 'MBRC-001',
        'address' => 'Kibada St, STE 108', 'branch_id' => $branch, 'status' => 'active',
    ]);
    $product = SavingsProduct::factory()->create(['name' => 'General Savings']);
    $account = SavingsAccount::factory()->create([
        'member_id' => $member->id, 'savings_product_id' => $product->id, 'account_no' => 'SA-001',
        'account_type' => 'voluntary', 'balance' => 0, 'branch_id' => $branch, 'status' => 'active',
    ]);

    $r = app(SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['account']['account_no'])->toBe('SA-001');
    expect($r['account']['account_type'])->toBe('Voluntary');
    expect($r['account']['product_name'])->toBe('General Savings');
    expect($r['member']['name'])->toBe('Maya Nyamu');
    expect($r['member']['member_number'])->toBe('MBRC-001');
    expect($r['member']['address'])->toBe('Kibada St, STE 108');
    expect($r['branch']['name'])->toBe('Main branch');
});

it('includes rows whose account_type is null or a morph alias (legacy data shape)', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;

    // Legacy MemberHelpers-style row: account_type is NULL
    Transaction::create([
        'reference' => 'LEGACY-DEP', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => null,
        'type' => 'deposit', 'amount' => 4_000_000,
        'transaction_date' => Carbon::parse('2026-02-10'),
    ]);
    // Modern morph-alias row: account_type stores the alias, not the FQCN
    Transaction::create([
        'reference' => 'MORPH-CHG', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => 'deposit-charge',
        'type' => 'deposit-charge', 'amount' => 0, 'charge_amount' => 6_000,
        'transaction_date' => Carbon::parse('2026-02-10'),
    ]);
    // Loan-typed row on the same account_id MUST be excluded
    Transaction::create([
        'reference' => 'LOAN-NOISE', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => 'loan',
        'type' => 'deposit', 'amount' => 999_999,
        'transaction_date' => Carbon::parse('2026-02-10'),
    ]);

    $r = app(SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['balances']['count'])->toBe(2);                  // legacy deposit + morph charge; loan excluded
    expect($r['balances']['total_credit'])->toBe(4_000_000.0); // the NULL-typed deposit
    expect($r['balances']['total_debit'])->toBe(6_000.0);      // the alias-typed charge (charge_amount used because amount=0)
});

it('excludes group savings transactions from personal savings statements', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;

    Transaction::create([
        'reference' => 'PERSONAL-DEP',
        'member_id' => $member->id,
        'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'type' => 'deposit',
        'amount' => 1000,
        'transaction_date' => Carbon::parse('2026-02-10'),
    ]);

    Transaction::create([
        'reference' => 'GROUP-DEP',
        'member_id' => $member->id,
        'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'group_savings_account_id' => 99,
        'type' => 'deposit',
        'amount' => 200000,
        'transaction_date' => Carbon::parse('2026-02-11'),
    ]);

    $r = app(SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['balances']['count'])->toBe(1);
    expect($r['balances']['total_credit'])->toBe(1000.0);
    expect($r['transactions'])->toHaveCount(1);
    expect($r['transactions'][0]['id'])->not->toBeNull();
});

it('debits legacy deposit_charges rows so the statement matches the stored balance', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;

    // Legacy CrudHelders initial-deposit shape: gross deposit + underscore-typed
    // charge row carrying the fee in charge_amount with amount = 0.
    Transaction::create([
        'reference' => 'INIT-DEP', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => null,
        'type' => 'deposit', 'amount' => 890_000,
        'transaction_date' => Carbon::parse('2026-02-01'),
    ]);
    Transaction::create([
        'reference' => 'INIT-CHG', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => null,
        'type' => 'deposit_charges', 'amount' => 0, 'charge_amount' => 4_000,
        'transaction_date' => Carbon::parse('2026-02-01'),
    ]);
    Transaction::create([
        'reference' => 'WDR', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => 'withdrawal',
        'type' => 'withdrawal', 'amount' => 70_000, 'charge_amount' => 2_000,
        'transaction_date' => Carbon::parse('2026-02-02'),
    ]);
    // Withdrawal-charge marker: fee off payout, never a balance movement
    Transaction::create([
        'reference' => 'WDR-CHG', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => 'withdrawal-charge',
        'type' => 'withdrawal-charge', 'amount' => 0, 'charge_amount' => 2_000,
        'transaction_date' => Carbon::parse('2026-02-02'),
    ]);

    $r = app(SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['warnings'])->toBeEmpty();
    expect($r['balances']['total_credit'])->toBe(890_000.0);
    expect($r['balances']['total_debit'])->toBe(74_000.0);  // 4,000 charge + 70,000 gross withdrawal
    expect($r['balances']['closing'])->toBe(816_000.0);
});

it('credits loan disbursements to savings and debits loan repayments from savings', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;

    Transaction::create([
        'reference' => 'LN-DISB', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => SavingsAccount::class,
        'type' => 'loan_disbursement', 'amount' => 895_000,
        'transaction_date' => Carbon::parse('2026-02-05'),
    ]);
    Transaction::create([
        'reference' => 'LN-REPAY', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => SavingsAccount::class,
        'type' => 'loan_repayment', 'amount' => 270_000,
        'transaction_date' => Carbon::parse('2026-02-06'),
    ]);

    $r = app(SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['warnings'])->toBeEmpty();
    expect($r['balances']['total_credit'])->toBe(895_000.0);
    expect($r['balances']['total_debit'])->toBe(270_000.0);
    expect($r['balances']['closing'])->toBe(625_000.0);
});

it('treats reversal marker rows as neutral without warning (pairs with the excluded original)', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;

    // Original withdrawal, reversed: excluded from the statement
    $original = Transaction::create([
        'reference' => 'W-ORIG', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => 'withdrawal',
        'type' => 'withdrawal', 'amount' => 600_000,
        'transaction_date' => Carbon::parse('2026-02-10'),
        'is_reversed' => true,
    ]);
    // Modern reversal marker row: type='reversal', original type in account_type.
    // The excluded original and this marker cancel, so the marker must be
    // neutral or the statement double-counts against the stored balance.
    Transaction::create([
        'reference' => 'W-REV', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => 'withdrawal',
        'type' => 'reversal', 'amount' => 600_000,
        'transaction_date' => Carbon::parse('2026-02-11'),
        'reversal_of' => $original->id,
    ]);
    Transaction::create([
        'reference' => 'DEP', 'member_id' => $member->id,
        'account_id' => $account->id, 'account_type' => 'deposit',
        'type' => 'deposit', 'amount' => 100_000,
        'transaction_date' => Carbon::parse('2026-02-12'),
    ]);

    $r = app(SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['warnings'])->toBeEmpty();
    expect($r['balances']['total_credit'])->toBe(100_000.0);
    expect($r['balances']['total_debit'])->toBe(0.0);
    expect($r['balances']['closing'])->toBe(100_000.0);
});
