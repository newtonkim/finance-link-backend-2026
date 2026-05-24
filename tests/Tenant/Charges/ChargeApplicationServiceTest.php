<?php

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Charges\Contracts\ChargeApplicationServiceInterface;
use App\Tenant\Modules\Members\Models\MemberCharge;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;
use Illuminate\Support\Facades\DB;

it('returns null when no charge applies for the event type', function () {
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);

    $service = app(ChargeApplicationServiceInterface::class);
    $result = $service->applyForSavingsEvent($account->id, 'deposit', 500.00, transactionId: null, actorId: 1);

    expect($result)->toBeNull();
    expect(MemberCharge::on('tenant')->count())->toBe(0);
});

it('creates a MemberCharge and posts a JournalEntry on first application', function () {
    // COA rows required by SavingsCoaResolver (gl_code 21102) and ChargeJournalService
    ChartOfAccount::on('tenant')->create([
        'gl_code' => '21102', 'name' => 'Voluntary Savings', 'account_type' => 'LIABILITY',
        'normal_balance' => 'CR', 'is_active' => true, 'is_postable' => true,
    ]);
    $incomeGl = ChartOfAccount::on('tenant')->create([
        'gl_code' => '4100', 'name' => 'Fee Income', 'account_type' => 'INCOME',
        'normal_balance' => 'CR', 'is_active' => true, 'is_postable' => true,
    ]);

    $generalCharge = GeneralCharge::factory()->create([
        'charge_type' => 'amount',
        'amount' => 30.00,
        'interval_type' => 'deposit',
        'credit_account_id' => $incomeGl->id,
        'name' => 'Deposit Fee',
    ]);
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
    SavingsProductCharge::factory()->create([
        'savings_product_id' => $product->id,
        'type' => 'deposit',
        'general_charge_id' => $generalCharge->id,
    ]);

    // Insert a stub transaction row to satisfy the FK on member_charges.transaction_id
    DB::connection('tenant')->table('transactions')->insert([
        'id' => 42,
        'reference' => 'TEST-TXN-42',
        'type' => 'deposit',
        'amount' => 1000.00,
        'charge_amount' => 0.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(ChargeApplicationServiceInterface::class);
    $memberCharge = $service->applyForSavingsEvent($account->id, 'deposit', 1000.00, transactionId: 42, actorId: 1);

    expect($memberCharge)->toBeInstanceOf(MemberCharge::class)
        ->and((float) $memberCharge->amount)->toBe(30.0)
        ->and($memberCharge->status)->toBe('applied')
        ->and($memberCharge->general_charge_id)->toBe($generalCharge->id)
        ->and($memberCharge->savings_account_id)->toBe($account->id);

    expect(JournalEntry::on('tenant')->where('journal_type', 'SAVINGS_CHARGE')->count())->toBe(1);

    $lines = JournalEntryLine::on('tenant')
        ->whereHas('journalEntry', fn ($q) => $q->where('journal_type', 'SAVINGS_CHARGE'))
        ->get();
    expect($lines)->toHaveCount(2);
});

it('is idempotent — calling twice with the same transaction_id creates only one MemberCharge', function () {
    ChartOfAccount::on('tenant')->create([
        'gl_code' => '21102', 'name' => 'Voluntary Savings', 'account_type' => 'LIABILITY',
        'normal_balance' => 'CR', 'is_active' => true, 'is_postable' => true,
    ]);
    $incomeGl = ChartOfAccount::on('tenant')->create([
        'gl_code' => '4100', 'name' => 'Fee Income', 'account_type' => 'INCOME',
        'normal_balance' => 'CR', 'is_active' => true, 'is_postable' => true,
    ]);

    $generalCharge = GeneralCharge::factory()->create([
        'charge_type' => 'amount',
        'amount' => 20.00,
        'interval_type' => 'deposit',
        'credit_account_id' => $incomeGl->id,
    ]);
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
    SavingsProductCharge::factory()->create([
        'savings_product_id' => $product->id,
        'type' => 'deposit',
        'general_charge_id' => $generalCharge->id,
    ]);

    // Insert a stub transaction row to satisfy the FK on member_charges.transaction_id
    DB::connection('tenant')->table('transactions')->insert([
        'id' => 42,
        'reference' => 'TEST-TXN-42',
        'type' => 'deposit',
        'amount' => 500.00,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(ChargeApplicationServiceInterface::class);
    $first = $service->applyForSavingsEvent($account->id, 'deposit', 500.00, transactionId: 42, actorId: 1);
    $second = $service->applyForSavingsEvent($account->id, 'deposit', 500.00, transactionId: 42, actorId: 1);

    expect(MemberCharge::on('tenant')->count())->toBe(1)
        ->and($first->id)->toBe($second->id);
    expect(JournalEntry::on('tenant')->where('journal_type', 'SAVINGS_CHARGE')->count())->toBe(1);
});
