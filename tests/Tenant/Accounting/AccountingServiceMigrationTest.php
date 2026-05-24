<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Transactions\Models\Transaction;

beforeEach(function () {
    ChartOfAccount::create([
        'gl_code' => '11101', 'name' => 'Cash', 'account_type' => 'ASSET',
        'account_subtype' => 'cash', 'normal_balance' => 'DR', 'level' => 4,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);
    $this->gl1112 = ChartOfAccount::create([
        'gl_code' => '11102', 'name' => 'Cash at Bank', 'account_type' => 'ASSET',
        'account_subtype' => 'bank', 'normal_balance' => 'DR', 'level' => 4,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);
    $this->gl2111 = ChartOfAccount::create([
        'gl_code' => '21101', 'name' => 'Mandatory Savings', 'account_type' => 'LIABILITY',
        'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 4,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);
    $this->gl2112 = ChartOfAccount::create([
        'gl_code' => '21102', 'name' => 'Voluntary Savings', 'account_type' => 'LIABILITY',
        'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 4,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);
    $this->gl4230 = ChartOfAccount::create([
        'gl_code' => '42300', 'name' => 'Account Maintenance Fees', 'account_type' => 'INCOME',
        'account_subtype' => 'fee_income', 'normal_balance' => 'CR', 'level' => 4,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);

    $this->product = SavingsProduct::create([
        'code' => 'MAN', 'name' => 'Mandatory', 'type' => 'standard', 'status' => 'active',
    ]);

    $this->member = Member::create([
        'name' => 'Test Member', 'member_number' => 'M-MIG-001',
        'code' => 'MMIG001',
        'email' => 'mig-'.uniqid().'@test.local',
        'status' => 'active',
        'password' => bcrypt('password'),
    ]);

    $this->mandatoryAccount = SavingsAccount::create([
        'savings_product_id' => $this->product->id,
        'member_id' => $this->member->id,
        'account_no' => 'MAN-MIG-001',
        'account_type' => 'mandatory',
        'balance' => 1000,
        'status' => 'active',
        'code' => 'MANMIG001',
    ]);

    $this->voluntaryAccount = SavingsAccount::create([
        'savings_product_id' => $this->product->id,
        'member_id' => $this->member->id,
        'account_no' => 'VOL-MIG-001',
        'account_type' => 'voluntary',
        'balance' => 1000,
        'status' => 'active',
        'code' => 'VOLMIG001',
    ]);

    $this->staff = Staff::factory()->create();
});

it('postChargeReversal debits income GL and credits mandatory liability', function () {
    $reversal = Transaction::create([
        'reference' => 'CHG-REV-001',
        'member_id' => $this->member->id,
        'type' => 'reversal',
        'amount' => 150,
        'payment_mode' => 'system',
        'deposited_by' => 'System',
        'transaction_date' => now()->toDateString(),
        'account_id' => $this->mandatoryAccount->id,
        'account_type' => SavingsAccount::class,
        'gl_credit_account_id' => $this->gl4230->id,
        'created_by' => $this->staff->id,
    ]);

    $je = app(SavingsJournalService::class)
        ->postChargeReversal($reversal, $this->mandatoryAccount);

    expect($je)->not->toBeNull();

    // DR income GL (4230)
    $this->assertDatabaseHas('journal_entry_lines', [
        'journal_entry_id' => $je->id,
        'account_id' => $this->gl4230->id,
        'debit' => 150,
        'credit' => 0,
    ]);

    // CR savings liability GL 2111 (mandatory — NOT 2112)
    $this->assertDatabaseHas('journal_entry_lines', [
        'journal_entry_id' => $je->id,
        'account_id' => $this->gl2111->id,
        'debit' => 0,
        'credit' => 150,
    ]);

    // Must NOT use 2112
    $this->assertDatabaseMissing('journal_entry_lines', [
        'journal_entry_id' => $je->id,
        'account_id' => $this->gl2112->id,
    ]);
});

it('postChargeReversal falls back to GL 4230 when no gl_credit_account_id', function () {
    $reversal = Transaction::create([
        'reference' => 'CHG-REV-002',
        'member_id' => $this->member->id,
        'type' => 'reversal',
        'amount' => 50,
        'payment_mode' => 'system',
        'deposited_by' => 'System',
        'transaction_date' => now()->toDateString(),
        'account_id' => $this->voluntaryAccount->id,
        'account_type' => SavingsAccount::class,
        'gl_credit_account_id' => null,
        'created_by' => $this->staff->id,
    ]);

    $je = app(SavingsJournalService::class)
        ->postChargeReversal($reversal, $this->voluntaryAccount);

    expect($je)->not->toBeNull();

    $this->assertDatabaseHas('journal_entry_lines', [
        'journal_entry_id' => $je->id,
        'account_id' => $this->gl4230->id,
        'debit' => 50,
    ]);

    $this->assertDatabaseHas('journal_entry_lines', [
        'journal_entry_id' => $je->id,
        'account_id' => $this->gl2112->id, // voluntary account → GL 2112
        'debit' => 0,
        'credit' => 50,
    ]);
});

it('reverseJournalEntry mirrors lines and marks original as reversed', function () {
    $service = app(SavingsJournalService::class);

    $depositTxn = Transaction::create([
        'reference' => 'DEP-REV-001',
        'member_id' => $this->member->id,
        'type' => 'deposit',
        'amount' => 300,
        'payment_mode' => 'cash',
        'deposited_by' => 'Teller',
        'transaction_date' => now()->toDateString(),
        'account_id' => $this->mandatoryAccount->id,
        'account_type' => SavingsAccount::class,
        'created_by' => $this->staff->id,
    ]);

    $originalJe = $service->postDeposit($depositTxn, $this->mandatoryAccount);
    expect($originalJe)->not->toBeNull();

    $reversalJe = $service->reverseJournalEntry(
        original: $originalJe,
        reversalReference: 'DEP-REV-REV-001',
        date: now()->toDateString(),
        reversedBy: $this->staff->id,
        narration: 'Test reversal',
    );

    expect($reversalJe)->not->toBeNull();

    // Original should be marked reversed
    $this->assertDatabaseHas('journal_entries', [
        'id' => $originalJe->id,
        'status' => 'reversed',
    ]);
    expect($originalJe->fresh()->reversed_at)->not->toBeNull();

    // Reversal JE must have swapped DR/CR from original
    $originalLines = $originalJe->load('lines')->lines;
    $reversalLines = $reversalJe->load('lines')->lines->keyBy('account_id');

    foreach ($originalLines as $origLine) {
        $mirror = $reversalLines[$origLine->account_id];
        expect((float) $origLine->debit)->toBe((float) $mirror->credit);
        expect((float) $origLine->credit)->toBe((float) $mirror->debit);
    }
});
