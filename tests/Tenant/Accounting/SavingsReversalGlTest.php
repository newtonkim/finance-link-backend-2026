<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Transactions\Models\Transaction;
use App\Tenant\Modules\Transactions\Services\ReversalService;

// ── Setup ────────────────────────────────────────────────────────────────────

beforeEach(function () {
    // Seed the chart of accounts entries needed by the COA resolver
    ChartOfAccount::create([
        'gl_code' => '11101', 'name' => 'Cash', 'account_type' => 'ASSET',
        'account_subtype' => 'Cash', 'normal_balance' => 'DR', 'level' => 4,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);
    ChartOfAccount::create([
        'gl_code' => '11102', 'name' => 'Cash at Bank', 'account_type' => 'ASSET',
        'account_subtype' => 'Bank', 'normal_balance' => 'DR', 'level' => 4,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);
    ChartOfAccount::create([
        'gl_code' => '21101', 'name' => 'Mandatory Savings', 'account_type' => 'LIABILITY',
        'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 4,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);
    ChartOfAccount::create([
        'gl_code' => '21102', 'name' => 'Voluntary Savings', 'account_type' => 'LIABILITY',
        'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 4,
        'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);

    $this->product = SavingsProduct::create([
        'code' => 'MAN', 'name' => 'Mandatory', 'type' => 'standard', 'status' => 'active',
    ]);

    $this->member = Member::create([
        'name' => 'Test Member',
        'member_number' => 'M-GL-001',
        'code' => 'MGL001',
        'email' => 'sav-rev-'.uniqid().'@test.local',
        'status' => 'active',
        'password' => bcrypt('password'),
    ]);

    $this->staff = Staff::factory()->create();
});

// ── Tests ────────────────────────────────────────────────────────────────────

it('deposit reversal fallback debits mandatory GL 21101', function () {
    $account = SavingsAccount::create([
        'savings_product_id' => $this->product->id,
        'member_id' => $this->member->id,
        'account_no' => 'MAN-001',
        'account_type' => 'mandatory',
        'balance' => 500,
        'status' => 'active',
        'code' => 'MAN001',
    ]);

    $deposit = Transaction::create([
        'reference' => 'DEP-TEST-001',
        'member_id' => $this->member->id,
        'type' => 'deposit',
        'amount' => 500,
        'payment_mode' => 'cash',
        'deposited_by' => 'Test',
        'transaction_date' => now()->toDateString(),
        'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'narration' => 'Test deposit',
        'created_by' => $this->staff->id,
    ]);

    $service = app(ReversalService::class);
    $service->requestReversal($deposit, $this->staff, 'Test reversal');

    $reversalRef = Transaction::where('reversal_of', $deposit->id)->value('reference');
    expect($reversalRef)->not->toBeNull();

    $mandatoryAccount = ChartOfAccount::where('gl_code', '21101')->first();

    $debitLine = GeneralLedger::whereHas('journalEntry', fn ($q) => $q->where('reference', $reversalRef))
        ->where('debit', '>', 0)
        ->where('account_id', $mandatoryAccount->id)
        ->first();

    expect($debitLine)->not->toBeNull(
        'Expected reversal JE to debit GL 21101 (mandatory savings) but it did not.'
    );
});

it('deposit reversal fallback does NOT use hardcoded GL 21102 for mandatory account', function () {
    $account = SavingsAccount::create([
        'savings_product_id' => $this->product->id,
        'member_id' => $this->member->id,
        'account_no' => 'MAN-002',
        'account_type' => 'mandatory',
        'balance' => 200,
        'status' => 'active',
        'code' => 'MAN002',
    ]);

    $deposit = Transaction::create([
        'reference' => 'DEP-TEST-002',
        'member_id' => $this->member->id,
        'type' => 'deposit',
        'amount' => 200,
        'payment_mode' => 'cash',
        'deposited_by' => 'Test',
        'transaction_date' => now()->toDateString(),
        'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'narration' => 'Test deposit',
        'created_by' => $this->staff->id,
    ]);

    $service = app(ReversalService::class);
    $service->requestReversal($deposit, $this->staff, 'Test reversal');

    $reversalRef = Transaction::where('reversal_of', $deposit->id)->value('reference');

    $voluntaryAccount = ChartOfAccount::where('gl_code', '21102')->first();

    $wrongGlLine = GeneralLedger::whereHas('journalEntry', fn ($q) => $q->where('reference', $reversalRef))
        ->where('debit', '>', 0)
        ->where('account_id', $voluntaryAccount->id)
        ->first();

    expect($wrongGlLine)->toBeNull(
        'Reversal JE must NOT debit GL 21102 for a mandatory savings account.'
    );
});
