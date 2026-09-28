<?php

use App\Models\Staff;
use App\Tenant\Modules\Accounting\Contracts\CashFlowStatementServiceInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use Carbon\Carbon;

/**
 * The statement of cash flows, direct method. Each test posts real journal entries
 * and checks the statement against figures worked out by hand, and that opening
 * cash plus the net cash flow always equals closing cash.
 */
beforeEach(function () {
    $staff = Staff::findOrFail(1);
    $staff->is_tenant_admin = true;
    $this->actingAs($staff);

    $account = function (string $code, string $name, string $type, string $subtype, ?ChartOfAccount $parent = null, ?string $line = null, bool $postable = true) {
        return ChartOfAccount::create([
            'gl_code' => 'CF'.$code.uniqid(), 'name' => $name, 'account_type' => $type, 'account_subtype' => $subtype,
            'normal_balance' => in_array($type, ['ASSET', 'EXPENSE'], true) ? 'DR' : 'CR',
            'level' => $parent ? 4 : 3, 'parent_id' => $parent?->id, 'is_postable' => $postable,
            'is_active' => true, 'is_control' => ! $postable, 'income_statement_line' => $line,
        ]);
    };

    // A cash group found by its template code, so a custom account under it counts as cash.
    $this->cashGroup = ChartOfAccount::where('gl_code', '11100')->first()
        ?? ChartOfAccount::create(['gl_code' => '11100', 'name' => 'Cash & Cash Equivalents', 'account_type' => 'ASSET',
            'account_subtype' => 'Current Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_postable' => false,
            'is_active' => true, 'is_control' => true]);
    $this->petty = $account('11101', 'Petty Cash', 'ASSET', 'Cash', $this->cashGroup);
    $this->bank = $account('11102', 'Bank', 'ASSET', 'Current Asset', $this->cashGroup);
    $this->loans = $account('11301', 'Personal Loans', 'ASSET', 'Loan');
    $this->savings = $account('21102', 'Voluntary Savings', 'LIABILITY', 'Member Deposit');
    $this->interest = $account('41100', 'Interest on Loans', 'INCOME', 'Interest', null, 'interest_income');
    $this->fees = $account('42200', 'Processing Fees', 'INCOME', 'Fees', null, 'fee_income');
    $this->rent = $account('53100', 'Rent', 'EXPENSE', 'Admin', null, 'administration');
    $this->computers = $account('12103', 'Computers', 'ASSET', 'Fixed Asset');
    $this->shares = $account('31100', 'Ordinary Shares', 'EQUITY', 'Share Capital');
    $this->borrowings = $account('22101', 'Bank Loans Payable', 'LIABILITY', 'Borrowings');
    $this->opening = ChartOfAccount::where('gl_code', '33900')->first()
        ?? ChartOfAccount::create(['gl_code' => '33900', 'name' => 'Opening Balance Control', 'account_type' => 'EQUITY',
            'account_subtype' => 'Retained Earnings', 'normal_balance' => 'CR', 'level' => 3, 'is_postable' => true,
            'is_active' => true, 'is_control' => false]);
});

/** @param  list<array{0: ChartOfAccount, 1: float|int, 2: float|int}>  $lines  account, debit, credit */
function cfPost(string $date, array $lines, array $extra = []): JournalEntry
{
    $entry = JournalEntry::create(array_merge([
        'entry_no' => 'CF-'.uniqid(), 'date' => $date, 'period_date' => $date,
        'fiscal_period' => substr($date, 0, 7), 'journal_type' => 'MANUAL',
        'status' => 'posted', 'currency_code' => 'UGX', 'branch_id' => 1,
        'is_system' => true, 'narration' => 'Cash flow test',
    ], $extra));

    foreach ($lines as [$account, $debit, $credit]) {
        GeneralLedger::create(['account_id' => $account->id, 'journal_entry_id' => $entry->id,
            'date' => $date, 'debit' => $debit, 'credit' => $credit, 'balance' => 0]);
    }

    return $entry;
}

function cfReport(string $from = '2026-01-01', string $to = '2026-03-31', ?string $compareFrom = null, ?string $compareTo = null, bool $hideZero = true): array
{
    return app(CashFlowStatementServiceInterface::class)->generate(
        Carbon::parse($from), Carbon::parse($to),
        $compareFrom ? Carbon::parse($compareFrom) : null, $compareTo ? Carbon::parse($compareTo) : null,
        $hideZero,
    );
}

function cfRow(array $report, string $key): ?array
{
    foreach ($report['sections'] as $section) {
        foreach ($section['rows'] as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
    }

    return null;
}

function cfSection(array $report, string $key): ?array
{
    return collect($report['sections'])->firstWhere('key', $key);
}

/** The quarter's activity used by several tests. */
function cfQuarter($t): void
{
    cfPost('2025-12-31', [[$t->petty, 1000, 0], [$t->opening, 0, 1000]]);             // cash before the period
    cfPost('2026-01-05', [[$t->petty, 500, 0], [$t->savings, 0, 490], [$t->fees, 0, 10]]); // deposit less a fee
    cfPost('2026-01-10', [[$t->loans, 400, 0], [$t->bank, 0, 380], [$t->fees, 0, 20]]);    // loan paid out net of fee
    cfPost('2026-02-01', [[$t->bank, 330, 0], [$t->loans, 0, 300], [$t->interest, 0, 30]]); // repayment with interest
    cfPost('2026-02-02', [[$t->bank, 200, 0], [$t->petty, 0, 200]]);                     // transfer between cash accounts
    cfPost('2026-02-03', [[$t->savings, 100, 0], [$t->petty, 0, 100]]);                  // withdrawal
    cfPost('2026-03-01', [[$t->rent, 50, 0], [$t->bank, 0, 50]]);                        // rent
    cfPost('2026-03-02', [[$t->computers, 150, 0], [$t->bank, 0, 150]]);                 // computer bought
    cfPost('2026-03-03', [[$t->petty, 60, 0], [$t->shares, 0, 60]]);                     // shares bought by a member
    cfPost('2026-03-04', [[$t->bank, 1000, 0], [$t->borrowings, 0, 1000]]);              // bank loan received
    cfPost('2026-03-05', [[$t->savings, 50, 0], [$t->loans, 0, 50]]);                    // loan repaid from savings: no cash
    cfPost('2026-03-06', [[$t->petty, 999, 0], [$t->fees, 0, 999]], ['status' => 'draft']); // draft: ignored
    cfPost('2026-03-07', [[$t->bank, 70, 0], [$t->opening, 0, 70]]);                     // balance brought onto the system
}

it('builds the statement from cash entries and reconciles opening to closing cash', function () {
    cfQuarter($this);

    $report = cfReport();

    expect(cfRow($report, 'fees_received')['amount'])->toBe('30.00')
        ->and(cfRow($report, 'interest_received')['amount'])->toBe('30.00')
        ->and(cfRow($report, 'suppliers_paid')['amount'])->toBe('-50.00')
        ->and(cfRow($report, 'loans_disbursed')['amount'])->toBe('-400.00')
        ->and(cfRow($report, 'loans_repaid')['amount'])->toBe('300.00')
        ->and(cfRow($report, 'savings_deposited')['amount'])->toBe('490.00')
        ->and(cfRow($report, 'savings_withdrawn')['amount'])->toBe('-100.00')
        ->and(cfSection($report, 'operating')['total'])->toBe('300.00')
        ->and(cfRow($report, 'ppe_purchased')['amount'])->toBe('-150.00')
        ->and(cfSection($report, 'investing')['total'])->toBe('-150.00')
        ->and(cfRow($report, 'shares_subscribed')['amount'])->toBe('60.00')
        ->and(cfRow($report, 'borrowings_received')['amount'])->toBe('1000.00')
        ->and(cfSection($report, 'financing')['total'])->toBe('1060.00')
        ->and(cfSection($report, 'other')['total'])->toBe('70.00');

    expect($report['summary']['opening_cash'])->toBe('1000.00')
        ->and($report['summary']['net_change'])->toBe('1280.00')
        ->and($report['summary']['closing_cash'])->toBe('2280.00')
        ->and($report['diagnostics']['difference'])->toBe('0.00')
        ->and($report['diagnostics']['classification_complete'])->toBeTrue();
});

it('leaves out transfers between cash accounts, entries that move no cash, and drafts', function () {
    cfQuarter($this);

    $report = cfReport();
    $accounts = collect($report['sections'])->flatMap(fn ($s) => $s['rows'])->flatMap(fn ($r) => $r['accounts'])->pluck('id');

    // The loan repaid from savings moved no cash, so the loan and savings lines only
    // show the cash repayment and withdrawal; the 999 draft never appears.
    expect(cfRow($report, 'loans_repaid')['amount'])->toBe('300.00')
        ->and(cfRow($report, 'savings_withdrawn')['amount'])->toBe('-100.00')
        ->and(cfRow($report, 'fees_received')['amount'])->toBe('30.00')
        ->and($accounts)->not->toContain($this->petty->id)
        ->and($accounts)->not->toContain($this->bank->id);
});

it('shows cash per account and each month\'s flows', function () {
    cfQuarter($this);

    $report = cfReport();
    $cash = collect($report['cash_accounts'])->keyBy('id');

    expect($cash[$this->petty->id]['opening'])->toBe('1000.00')
        ->and($cash[$this->petty->id]['closing'])->toBe('1260.00')
        ->and($cash[$this->bank->id]['closing'])->toBe('1020.00');

    expect(array_column($report['monthly'], 'month'))->toBe(['2026-01', '2026-02', '2026-03'])
        ->and(array_column($report['monthly'], 'net'))->toBe(['120.00', '230.00', '930.00'])
        ->and($report['monthly'][2]['closing_cash'])->toBe('2280.00')
        ->and($report['monthly'][2]['financing'])->toBe('1060.00');
});

it('compares with another period', function () {
    cfPost('2025-02-01', [[$this->bank, 330, 0], [$this->loans, 0, 300], [$this->interest, 0, 30]]);
    cfPost('2026-02-01', [[$this->bank, 110, 0], [$this->loans, 0, 100], [$this->interest, 0, 10]]);

    $report = cfReport();

    expect($report['compare_from'])->toBe('2025-01-01')
        ->and(cfRow($report, 'loans_repaid')['amount'])->toBe('100.00')
        ->and(cfRow($report, 'loans_repaid')['compare_amount'])->toBe('300.00')
        ->and($report['summary']['compare_net_change'])->toBe('330.00')
        ->and($report['summary']['opening_cash'])->toBe('330.00');
});

it('reports accounts it cannot classify, and still reconciles', function () {
    $odd = ChartOfAccount::create(['gl_code' => 'CFX'.uniqid(), 'name' => 'Suspense', 'account_type' => 'LIABILITY',
        'account_subtype' => 'Suspense', 'normal_balance' => 'CR', 'level' => 3, 'is_postable' => true, 'is_active' => true, 'is_control' => false]);
    cfPost('2026-01-15', [[$this->bank, 25, 0], [$odd, 0, 25]]);

    $report = cfReport();

    expect(cfRow($report, 'other_operating')['amount'])->toBe('25.00')
        ->and($report['diagnostics']['classification_complete'])->toBeFalse()
        ->and($report['diagnostics']['issues'][0]['account_id'])->toBe($odd->id)
        ->and($report['diagnostics']['difference'])->toBe('0.00');
});

it('flags an entry that moved cash but does not balance', function () {
    cfPost('2026-01-20', [[$this->bank, 100, 0], [$this->fees, 0, 90]]);

    expect(cfReport()['diagnostics']['difference'])->toBe('10.00');
});

it('hides empty lines unless asked to show them', function () {
    cfPost('2026-01-05', [[$this->petty, 500, 0], [$this->savings, 0, 500]]);

    expect(cfSection(cfReport(), 'investing')['rows'])->toBe([])
        ->and(cfSection(cfReport(hideZero: false), 'investing')['rows'])->toHaveCount(4)
        ->and(collect(cfReport()['sections'])->pluck('key')->all())->toBe(['operating', 'investing', 'financing']);
});

it('serves the statement over the API, defaulting to the year to date', function () {
    cfPost(now()->toDateString(), [[$this->petty, 500, 0], [$this->savings, 0, 500]]);

    $this->getJson('/api/v1/tenant/reports/cash-flow')
        ->assertOk()
        ->assertJsonPath('to', now()->toDateString())
        ->assertJsonPath('method', 'direct')
        ->assertJsonStructure(['summary' => ['opening_cash', 'closing_cash', 'net_change', 'operating'], 'sections', 'monthly', 'cash_accounts', 'diagnostics']);

    $this->getJson('/api/v1/tenant/reports/cash-flow?from=2026-03-01')->assertUnprocessable();
    $this->getJson('/api/v1/tenant/reports/cash-flow?from=2026-03-01&to=2026-02-01')->assertUnprocessable();
});

it('only reports the branches the staff member may see', function () {
    $staff = Staff::findOrFail(1);
    $staff->is_tenant_admin = false;
    $staff->branch_id = 1;
    $this->actingAs($staff);

    cfPost('2026-01-05', [[$this->petty, 500, 0], [$this->savings, 0, 500]], ['branch_id' => 1]);
    cfPost('2026-01-06', [[$this->petty, 900, 0], [$this->savings, 0, 900]], ['branch_id' => 2]);
    cfPost('2025-12-01', [[$this->petty, 40, 0], [$this->opening, 0, 40]], ['branch_id' => 2]);

    $report = cfReport();

    expect(cfRow($report, 'savings_deposited')['amount'])->toBe('500.00')
        ->and($report['summary']['opening_cash'])->toBe('0.00')
        ->and($report['summary']['closing_cash'])->toBe('500.00');
});
