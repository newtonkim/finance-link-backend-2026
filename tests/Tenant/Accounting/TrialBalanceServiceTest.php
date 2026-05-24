<?php

use App\Tenant\Modules\Accounting\Contracts\TrialBalanceServiceInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use Carbon\Carbon;

beforeEach(function () {
    $this->bankAccount = ChartOfAccount::create([
        'gl_code' => '1112', 'name' => 'Cash at Bank',
        'account_type' => 'ASSET', 'account_subtype' => 'Bank',
        'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true,
        'is_active' => true,
    ]);
    $this->savingsAccount = ChartOfAccount::create([
        'gl_code' => '2111', 'name' => 'Mandatory Savings',
        'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit',
        'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true,
        'is_active' => true,
    ]);

    $je = JournalEntry::create([
        'entry_no' => 'JE-TEST-001', 'date' => '2026-01-15',
        'period_date' => '2026-01-15', 'fiscal_period' => '2026-01',
        'reference' => 'TEST-001', 'narration' => 'Test deposit',
        'journal_type' => 'SAVINGS_DEPOSIT', 'status' => 'posted',
        'is_system' => true, 'posted_at' => now(),
    ]);

    GeneralLedger::create([
        'account_id' => $this->bankAccount->id, 'journal_entry_id' => $je->id,
        'date' => '2026-01-15', 'debit' => 100000, 'credit' => 0, 'balance' => 100000, 'narration' => 'Test',
    ]);
    GeneralLedger::create([
        'account_id' => $this->savingsAccount->id, 'journal_entry_id' => $je->id,
        'date' => '2026-01-15', 'debit' => 0, 'credit' => 100000, 'balance' => 100000, 'narration' => 'Test',
    ]);

    $this->service = app(TrialBalanceServiceInterface::class);
});

it('asOfDate returns accounts with cumulative totals and is balanced', function () {
    $result = $this->service->asOfDate(Carbon::parse('2026-01-31'));

    $accounts = collect($result['accounts']);
    $bankRow  = $accounts->firstWhere('gl_code', '1112');
    $savRow   = $accounts->firstWhere('gl_code', '2111');

    expect($bankRow)->not->toBeNull();
    expect((float) $bankRow['closing_debit'])->toBe(100000.0);
    expect((float) $bankRow['closing_credit'])->toBe(0.0);

    expect($savRow)->not->toBeNull();
    expect((float) $savRow['closing_credit'])->toBe(100000.0);

    expect($result['totals']['is_balanced'])->toBeTrue();
    expect((float) $result['totals']['total_closing_debit'])
        ->toBe((float) $result['totals']['total_closing_credit']);
});

it('forPeriod separates opening from period movements', function () {
    $je2 = JournalEntry::create([
        'entry_no' => 'JE-TEST-002', 'date' => '2026-02-10',
        'period_date' => '2026-02-10', 'fiscal_period' => '2026-02',
        'reference' => 'TEST-002', 'narration' => 'Feb deposit',
        'journal_type' => 'SAVINGS_DEPOSIT', 'status' => 'posted',
        'is_system' => true, 'posted_at' => now(),
    ]);
    GeneralLedger::create([
        'account_id' => $this->bankAccount->id, 'journal_entry_id' => $je2->id,
        'date' => '2026-02-10', 'debit' => 50000, 'credit' => 0, 'balance' => 150000, 'narration' => 'Feb',
    ]);
    GeneralLedger::create([
        'account_id' => $this->savingsAccount->id, 'journal_entry_id' => $je2->id,
        'date' => '2026-02-10', 'debit' => 0, 'credit' => 50000, 'balance' => 150000, 'narration' => 'Feb',
    ]);

    $result = $this->service->forPeriod(Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'));
    $accounts = collect($result['accounts']);
    $bankRow  = $accounts->firstWhere('gl_code', '1112');

    expect((float) $bankRow['opening_debit'])->toBe(100000.0);
    expect((float) $bankRow['period_debit'])->toBe(50000.0);
    expect((float) $bankRow['closing_debit'])->toBe(150000.0);

    expect($result['totals']['is_balanced'])->toBeTrue();
});

it('ledgerLines returns paginated lines with running balance', function () {
    $paginator = $this->service->ledgerLines(
        $this->bankAccount->id,
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        1
    );

    expect($paginator->total())->toBe(1);
    $line = $paginator->items()[0];
    expect((float) $line['debit'])->toBe(100000.0);
    expect((float) $line['running_balance'])->toBeGreaterThan(0);
});
