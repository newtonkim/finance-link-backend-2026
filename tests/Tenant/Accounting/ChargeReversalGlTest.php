<?php

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;

beforeEach(function () {
    // GL 21101 — Mandatory Savings Deposits (liability)
    $this->mandatorySavingsLiability = ChartOfAccount::create([
        'gl_code' => '21101',
        'name' => 'Mandatory Savings Deposits',
        'account_type' => 'LIABILITY',
        'account_subtype' => 'current_liability',
        'normal_balance' => 'CR',
        'level' => 2,
        'is_control' => false,
        'is_postable' => true,
        'is_active' => true,
        'allow_manual' => false,
        'sort_order' => 10,
    ]);

    // GL 42300 — Account Maintenance Fees (income)
    $this->maintenanceFees = ChartOfAccount::create([
        'gl_code' => '42300',
        'name' => 'Account Maintenance Fees',
        'account_type' => 'INCOME',
        'account_subtype' => 'fee_income',
        'normal_balance' => 'CR',
        'level' => 2,
        'is_control' => false,
        'is_postable' => true,
        'is_active' => true,
        'allow_manual' => false,
        'sort_order' => 20,
    ]);
});

it('charge reversal debits income GL and credits mandatory savings liability', function () {
    // Mandatory savings account so resolveSavingsLiabilityAccount() picks GL 21101
    $savingsAccount = SavingsAccount::create([
        'account_no' => 'SV-TEST-001',
        'code' => 'SV-TEST-001',
        'account_type' => 'mandatory',
        'balance' => 1000.00,
        'status' => 'active',
    ]);

    // Reversal transaction — gl_credit_account_id points to the income GL being reversed
    $reversalTxn = Transaction::create([
        'reference' => 'REV-TEST-001',
        'type' => 'reversal',
        'amount' => 500.00,
        'transaction_date' => '2026-04-26',
        'gl_credit_account_id' => $this->maintenanceFees->id,
        'narration' => 'Charge reversal test',
    ]);

    $je = app(SavingsJournalService::class)->postChargeReversal($reversalTxn, $savingsAccount);

    expect($je)->toBeInstanceOf(JournalEntry::class);
    expect($je->id)->not->toBeNull();

    // Line 1: DR income GL (42300 — maintenanceFees)
    $this->assertDatabaseHas('journal_entry_lines', [
        'journal_entry_id' => $je->id,
        'account_id' => $this->maintenanceFees->id,
        'debit' => 500.00,
        'credit' => 0.00,
    ]);

    // Line 2: CR mandatory savings liability (21101)
    $this->assertDatabaseHas('journal_entry_lines', [
        'journal_entry_id' => $je->id,
        'account_id' => $this->mandatorySavingsLiability->id,
        'debit' => 0.00,
        'credit' => 500.00,
    ]);
});
