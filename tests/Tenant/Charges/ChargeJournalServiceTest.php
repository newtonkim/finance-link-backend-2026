<?php

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Charges\Contracts\ChargeJournalServiceInterface;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

it('posts DR savings liability CR income when credit_account_id is set on the charge', function () {
    // Arrange: create two COA accounts directly (no factory for ChartOfAccount)
    $savingsGl = ChartOfAccount::create([
        'gl_code' => '21102',
        'name' => 'Members Savings',
        'account_type' => 'LIABILITY',
        'account_subtype' => 'Deposits',
        'normal_balance' => 'CR',
        'level' => 3,
        'is_control' => false,
        'is_postable' => true,
        'is_active' => true,
    ]);

    $incomeGl = ChartOfAccount::create([
        'gl_code' => '4100',
        'name' => 'Charge Income',
        'account_type' => 'INCOME',
        'account_subtype' => 'Fee Income',
        'normal_balance' => 'CR',
        'level' => 3,
        'is_control' => false,
        'is_postable' => true,
        'is_active' => true,
    ]);

    $generalCharge = GeneralCharge::factory()->create(['credit_account_id' => $incomeGl->id]);
    $account = SavingsAccount::factory()->create();
    $account->loadMissing('savingsProduct');

    $service = app(ChargeJournalServiceInterface::class);
    $je = $service->post(
        charge: $generalCharge,
        fee: 50.00,
        memberId: $account->member_id,
        savingsAccount: $account,
        reference: 'TEST-REF-001',
        postedBy: 1
    );

    expect($je)->toBeInstanceOf(JournalEntry::class);

    $lines = JournalEntryLine::on('tenant')->where('journal_entry_id', $je->id)->get();
    expect($lines)->toHaveCount(2);

    $drLine = $lines->firstWhere('debit', '>', 0);
    $crLine = $lines->firstWhere('credit', '>', 0);

    expect((float) $drLine->debit)->toBe(50.0);
    expect((float) $crLine->credit)->toBe(50.0);
    expect($crLine->account_id)->toBe($incomeGl->id);
    // Assert DR line targets the savings liability GL account
    expect($drLine->account_id)->toBe($savingsGl->id);
    // Assert member_id is set on the DR line
    expect((int) $drLine->member_id)->toBe((int) $account->member_id);
});

it('throws DomainException when credit_account_id on the charge does not exist in COA', function () {
    // Need at least one COA row for the savings liability resolver
    ChartOfAccount::create([
        'gl_code' => '21102',
        'name' => 'Members Savings',
        'account_type' => 'LIABILITY',
        'account_subtype' => 'Deposits',
        'normal_balance' => 'CR',
        'level' => 3,
        'is_control' => false,
        'is_postable' => true,
        'is_active' => true,
    ]);

    $generalCharge = GeneralCharge::factory()->create(['credit_account_id' => 99999]);
    $account = SavingsAccount::factory()->create();

    $service = app(ChargeJournalServiceInterface::class);

    expect(fn () => $service->post($generalCharge, 50.00, $account->member_id, $account, 'REF-002', 1))
        ->toThrow(DomainException::class);
});

it('throws DomainException when credit_account_id is null on the charge', function () {
    ChartOfAccount::create([
        'gl_code' => '21102',
        'name' => 'Members Savings',
        'account_type' => 'LIABILITY',
        'account_subtype' => 'Deposits',
        'normal_balance' => 'CR',
        'level' => 3,
        'is_control' => false,
        'is_postable' => true,
        'is_active' => true,
    ]);
    $generalCharge = GeneralCharge::factory()->create(['credit_account_id' => null]);
    $account = SavingsAccount::factory()->create();

    $service = app(ChargeJournalServiceInterface::class);

    expect(fn () => $service->post($generalCharge, 50.00, $account->member_id, $account, 'REF-003', 1))
        ->toThrow(DomainException::class);
});

it('refuses to post when the target accounting period is locked', function () {
    // Two postable COA rows so resolution succeeds up to the period check.
    ChartOfAccount::create([
        'gl_code' => '21102',
        'name' => 'Members Savings',
        'account_type' => 'LIABILITY',
        'account_subtype' => 'Deposits',
        'normal_balance' => 'CR',
        'level' => 3,
        'is_control' => false,
        'is_postable' => true,
        'is_active' => true,
    ]);
    $incomeGl = ChartOfAccount::create([
        'gl_code' => '42999',
        'name' => 'Test Charge Income',
        'account_type' => 'INCOME',
        'account_subtype' => 'Fee Income',
        'normal_balance' => 'CR',
        'level' => 3,
        'is_control' => false,
        'is_postable' => true,
        'is_active' => true,
    ]);

    // Lock the period the JE will fall into (today's YYYY-MM).
    \Illuminate\Support\Facades\DB::connection('tenant')->table('accounting_periods')->insert([
        'period_code' => date('Y-m'),
        'is_locked' => true,
        'locked_by' => null,
        'locked_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $generalCharge = GeneralCharge::factory()->create(['credit_account_id' => $incomeGl->id]);
    $account = SavingsAccount::factory()->create();

    $service = app(ChargeJournalServiceInterface::class);

    expect(fn () => $service->post($generalCharge, 50.00, $account->member_id, $account, 'REF-PERIOD-LOCK', 1))
        ->toThrow(Exception::class, 'closed');
});
