<?php

use App\Models\Member;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Savings\Contracts\FdMaturityAccountingServiceInterface;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;

beforeEach(function () {
    $this->member = Member::create([
        'name' => 'Test Member',
        'member_number' => 'M-FD-001',
        'code' => 'MFD001',
        'email' => 'fd-maturity-'.uniqid().'@test.local',
        'status' => 'active',
        'password' => bcrypt('password'),
    ]);

    $this->fdAccount = ChartOfAccount::create([
        'gl_code' => '21103', 'name' => 'Fixed Deposits',
        'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit',
        'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true,
    ]);
    $this->volAccount = ChartOfAccount::create([
        'gl_code' => '21102', 'name' => 'Voluntary Savings Deposits',
        'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit',
        'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true,
    ]);
    $this->mandAccount = ChartOfAccount::create([
        'gl_code' => '21101', 'name' => 'Mandatory Savings Deposits',
        'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit',
        'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true,
    ]);

    $fdProduct = SavingsProduct::create([
        'code' => 'FD01', 'name' => 'Fixed Deposit', 'type' => 'fixed', 'status' => 'active',
    ]);
    $volProduct = SavingsProduct::create([
        'code' => 'SAV01', 'name' => 'Regular Savings', 'type' => 'standard', 'status' => 'active',
    ]);

    $this->oldFd = SavingsAccount::create([
        'savings_product_id' => $fdProduct->id,
        'account_no' => 'FD-000001', 'code' => 'FD000001', 'account_type' => 'fixed',
        'balance' => 150000.00, 'status' => 'active', 'member_id' => $this->member->id,
    ]);
    $this->newFd = SavingsAccount::create([
        'savings_product_id' => $fdProduct->id,
        'account_no' => 'FD-000002', 'code' => 'FD000002', 'account_type' => 'fixed',
        'balance' => 0.00, 'status' => 'active', 'member_id' => $this->member->id,
    ]);
    $this->volSavings = SavingsAccount::create([
        'savings_product_id' => $volProduct->id,
        'account_no' => 'SAV-000001', 'code' => 'SAV000001', 'account_type' => 'voluntary',
        'balance' => 5000.00, 'status' => 'active', 'member_id' => $this->member->id,
    ]);

    $this->service = app(FdMaturityAccountingServiceInterface::class);
});

it('postRollover posts DR old FD sub-ledger CR new FD sub-ledger', function () {
    $je = $this->service->postRollover($this->oldFd, $this->newFd, 1);

    expect($je)->toBeInstanceOf(JournalEntry::class);
    expect($je->journal_type)->toBe('FD_ROLLOVER');

    $lines = JournalEntryLine::on('tenant')->where('journal_entry_id', $je->id)->get();
    expect($lines)->toHaveCount(2);

    $totalDebit = $lines->sum('debit');
    $totalCredit = $lines->sum('credit');
    expect(number_format((float) $totalDebit, 2))->toBe(number_format((float) $totalCredit, 2));
    expect((float) $totalDebit)->toBe(150000.0);

    // Both lines post to GL 2113
    $glLines = GeneralLedger::on('tenant')->where('journal_entry_id', $je->id)->get();
    expect($glLines)->toHaveCount(2);
    $glLines->each(fn ($gl) => expect($gl->account_id)->toBe($this->fdAccount->id));
});

it('postPayout posts DR 2113 CR voluntary savings GL', function () {
    $je = $this->service->postPayout($this->oldFd, $this->volSavings, 1);

    expect($je->journal_type)->toBe('FD_PAYOUT');

    $lines = JournalEntryLine::on('tenant')->where('journal_entry_id', $je->id)->get();
    expect($lines)->toHaveCount(2);
    expect(number_format((float) $lines->sum('debit'), 2))
        ->toBe(number_format((float) $lines->sum('credit'), 2));

    $debitLine = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    expect($debitLine->account_id)->toBe($this->fdAccount->id);
    expect($creditLine->account_id)->toBe($this->volAccount->id);
    expect((float) $debitLine->debit)->toBe(150000.0);
});

it('postConversion posts DR 2113 CR 2112', function () {
    $je = $this->service->postConversion($this->oldFd, 1);

    expect($je->journal_type)->toBe('FD_CONVERSION');

    $lines = JournalEntryLine::on('tenant')->where('journal_entry_id', $je->id)->get();
    expect($lines)->toHaveCount(2);
    expect(number_format((float) $lines->sum('debit'), 2))
        ->toBe(number_format((float) $lines->sum('credit'), 2));

    $debitLine = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    expect($debitLine->account_id)->toBe($this->fdAccount->id);
    expect($creditLine->account_id)->toBe($this->volAccount->id);
});

it('postPayout posts DR 2113 CR mandatory savings GL when target is mandatory', function () {
    $mandProduct = SavingsProduct::create([
        'code' => 'MAND01', 'name' => 'Mandatory Savings', 'type' => 'standard', 'status' => 'active',
    ]);
    $mandSavings = SavingsAccount::create([
        'savings_product_id' => $mandProduct->id,
        'account_no' => 'MAND-000001', 'account_type' => 'mandatory',
        'balance' => 0.00, 'status' => 'active', 'member_id' => $this->member->id,
        'code' => 'MAND-000001',
    ]);

    $je = $this->service->postPayout($this->oldFd, $mandSavings, 1);

    $lines = JournalEntryLine::on('tenant')->where('journal_entry_id', $je->id)->get();
    $creditLine = $lines->firstWhere('credit', '>', 0);
    expect($creditLine->account_id)->toBe($this->mandAccount->id);
});
