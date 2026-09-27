<?php

use App\Models\Staff;
use App\Tenant\Http\Controllers\Api\V1\IncomeStatementController;
use App\Tenant\Modules\Accounting\Contracts\BalanceSheetServiceInterface;
use App\Tenant\Modules\Accounting\Contracts\IncomeStatementServiceInterface;
use App\Tenant\Modules\Accounting\Contracts\TrialBalanceServiceInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Support\IncomeStatementLines;
use App\Tenant\Modules\Settings\Models\FinancialYear;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

function isAccount(string $type = 'INCOME', ?string $line = 'interest_income', array $extra = []): ChartOfAccount
{
    return ChartOfAccount::create(array_merge([
        'gl_code' => 'IS'.uniqid(), 'name' => 'Test account', 'account_type' => $type,
        'normal_balance' => $type === 'INCOME' ? 'CR' : 'DR', 'level' => 3,
        'is_postable' => true, 'is_active' => true, 'is_control' => false, 'income_statement_line' => $line,
    ], $extra));
}

function isPost(ChartOfAccount $account, string $date, string $debit, string $credit, array $extra = []): JournalEntry
{
    $entry = JournalEntry::create(array_merge([
        'entry_no' => 'IS-'.uniqid(), 'date' => $date, 'period_date' => $date,
        'fiscal_period' => substr($date, 0, 7), 'journal_type' => 'MANUAL',
        'status' => 'posted', 'currency_code' => 'UGX', 'branch_id' => 1,
        'is_system' => true, 'narration' => 'Income statement test',
    ], $extra));
    GeneralLedger::create(['account_id' => $account->id, 'journal_entry_id' => $entry->id,
        'date' => $date, 'debit' => $debit, 'credit' => $credit, 'balance' => 999]);

    return $entry;
}

function isReport(string $from = '2026-01-01', string $to = '2026-12-31'): array
{
    return app(IncomeStatementServiceInterface::class)->generate(Carbon::parse($from), Carbon::parse($to));
}

beforeEach(function () {
    $staff = Staff::findOrFail(1);
    $staff->is_tenant_admin = true;
    $this->actingAs($staff);
});

it('calculates the SACCO statement with exact decimal amounts and excludes balance sheet activity', function () {
    foreach ([['INCOME', 'interest_income', '1000.00'], ['EXPENSE', 'interest_expense', '100.00'],
        ['INCOME', 'fee_income', '200.00'], ['EXPENSE', 'impairment', '50.00'],
        ['EXPENSE', 'staff_costs', '300.00'], ['EXPENSE', 'depreciation', '20.00'],
        ['INCOME', 'investment_income', '40.00'], ['EXPENSE', 'income_tax', '70.00']] as [$type, $line, $amount]) {
        isPost(isAccount($type, $line), '2026-06-01', $type === 'EXPENSE' ? $amount : '0', $type === 'INCOME' ? $amount : '0');
    }
    isPost(isAccount('ASSET', null), '2026-06-01', '50000', '0');
    $result = isReport();
    $rows = collect($result['rows'])->keyBy('key');
    expect($rows['net_interest']['amount'])->toBe('900.00')
        ->and($rows['operating_income']['amount'])->toBe('1100.00')
        ->and($rows['operating_surplus']['amount'])->toBe('730.00')
        ->and($rows['before_tax']['amount'])->toBe('770.00')
        ->and($result['totals']['surplus'])->toBe('700.00')
        ->and($result['diagnostics']['difference'])->toBe('0.00');
});

it('retains reversed originals in their own period, includes refunds and excludes drafts', function () {
    $account = isAccount();
    isPost($account, '2026-01-31', '0', '100.10', ['status' => 'reversed']);
    isPost($account, '2026-02-01', '100.10', '0');
    isPost($account, '2026-01-31', '0', '500', ['status' => 'draft']);
    expect(isReport('2026-01-01', '2026-01-31')['totals']['surplus'])->toBe('100.10')
        ->and(isReport('2026-02-01', '2026-02-28')['totals']['surplus'])->toBe('-100.10')
        ->and(isReport()['totals']['surplus'])->toBe('0.00');
});

it('preserves unmapped, inactive, deleted and header movements exactly once', function () {
    $parent = isAccount('INCOME', 'fee_income', ['is_postable' => false]);
    $child = isAccount('INCOME', null, ['parent_id' => $parent->id, 'is_active' => false]);
    $unknown = isAccount('EXPENSE', null);
    isPost($parent, '2026-01-01', '0', '1.01');
    isPost($child, '2026-01-01', '0', '2.02');
    isPost($unknown, '2026-01-01', '0.03', '0');
    $child->delete();
    $result = isReport();
    expect($result['totals']['surplus'])->toBe('3.00')
        ->and(collect($result['rows'])->firstWhere('key', 'fee_income')['amount'])->toBe('3.03')
        ->and($result['diagnostics']['classification_complete'])->toBeFalse()
        ->and($result['diagnostics']['issues'])->toHaveCount(2);
});

it('supports explicit overrides and reports cycles without losing money', function () {
    $parent = isAccount('INCOME', 'interest_income');
    $child = isAccount('INCOME', 'fee_income', ['parent_id' => $parent->id]);
    isPost($child, '2026-03-01', '0', '10');
    expect(collect(isReport()['rows'])->firstWhere('key', 'fee_income')['amount'])->toBe('10.00');
    $parent->update(['parent_id' => $child->id]);
    $result = isReport();
    expect($result['totals']['surplus'])->toBe('10.00')
        ->and($result['diagnostics']['issues'][0]['message'])->toContain('cycle');
});

it('keeps comparison-only accounts and clamps leap-day comparisons', function () {
    $account = isAccount();
    isPost($account, '2023-02-28', '0', '12.34');
    $result = isReport('2024-02-29', '2024-02-29');
    expect($result['compare_from'])->toBe('2023-02-28')
        ->and($result['totals']['compare_surplus'])->toBe('12.34')
        ->and($result['rows'][0]['accounts'])->toHaveCount(1);
});

it('excludes closing transfers while retaining adjustments and exposes the reconciliation bridge', function () {
    $account = isAccount();
    isPost($account, '2026-12-31', '0', '100');
    isPost($account, '2026-12-31', '10', '0', ['journal_type' => 'ADJUSTMENT']);
    isPost($account, '2026-12-31', '90', '0', ['is_closing_entry' => true]);
    $result = isReport();
    expect($result['totals']['surplus'])->toBe('90.00')
        ->and($result['diagnostics']['closing_transfers_excluded'])->toBe('-90.00');
});

it('enforces branch visibility for both report and ledger', function () {
    $staff = Staff::findOrFail(1);
    $staff->is_tenant_admin = false;
    $staff->branch_id = 1;
    $this->actingAs($staff);
    $account = isAccount();
    isPost($account, '2026-01-01', '0', '10', ['branch_id' => 1]);
    isPost($account, '2026-01-01', '0', '900', ['branch_id' => 2]);
    expect(isReport()['totals']['surplus'])->toBe('10.00');
    $ledger = app(IncomeStatementServiceInterface::class)->ledger($account->id, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));
    expect($ledger['total'])->toBe(1)->and($ledger['data'][0]['credit'])->toBe('10.00');
    $staff->branch_id = null;
    $this->actingAs($staff);
    expect(isReport()['totals']['surplus'])->toBe('0.00');
});

it('calculates running period movement across pages with backdated inserts', function () {
    $account = isAccount();
    for ($i = 0; $i < 50; $i++) {
        isPost($account, '2026-02-01', '0', '0.10');
    }
    isPost($account, '2026-01-01', '0', '1.01');
    isPost($account, '2025-12-31', '0', '999');
    $ledger = app(IncomeStatementServiceInterface::class)->ledger($account->id, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), 2);
    expect($ledger['total'])->toBe(51)->and($ledger['data'][0]['running_movement'])->toBe('6.01');
});

it('rejects foreign-currency movements instead of combining units', function () {
    isPost(isAccount(), '2026-01-01', '0', '20', ['currency_code' => 'USD']);
    isReport();
})->throws(ValidationException::class);

it('uses configured financial years for default requests and rejects partial or invalid periods', function () {
    Carbon::setTestNow('2026-09-27');
    try {
        FinancialYear::create(['name' => 'July FY', 'start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
        $response = app(IncomeStatementController::class)->index(Request::create('/', 'GET'))->getData(true);
        expect($response['from'])->toBe('2026-07-01')->and($response['period_default'])->toBe('financial_year');
        foreach ([['from' => '2026-01-01'], ['from' => '2026-02-30', 'to' => '2026-03-01'],
            ['from' => '2026-04-01', 'to' => '2026-03-01'], ['compare_from' => '2025-01-01'], ['branch_id' => 'invalid']] as $params) {
            expect(fn () => app(IncomeStatementController::class)->index(Request::create('/', 'GET', $params)))->toThrow(ValidationException::class);
        }
    } finally {
        Carbon::setTestNow();
    }
});

it('only bootstraps known template identities and validates mapping types', function () {
    expect(IncomeStatementLines::bootstrap('41000', 'Interest Income', 'INCOME'))->toBe('interest_income')
        ->and(IncomeStatementLines::bootstrap('41000', 'Custom fees', 'INCOME'))->toBeNull()
        ->and(IncomeStatementLines::bootstrap('41000', 'Interest Income', 'EXPENSE'))->toBeNull()
        ->and(IncomeStatementLines::keysFor('EXPENSE'))->not->toContain('interest_income');
});

it('serves the authenticated report and validates account mappings through the API', function () {
    $staff = Staff::findOrFail(1);
    $staff->status = 'active';
    $staff->is_tenant_admin = true;
    $this->actingAs($staff, 'web');
    $this->actingAs($staff, 'tenant');
    $account = isAccount();
    isPost($account, '2026-01-01', '0', '42.10');
    $this->getJson('/api/v1/tenant/reports/income-statement?from=2026-01-01&to=2026-12-31')
        ->assertOk()->assertJsonPath('totals.surplus', '42.10');
    $this->getJson('/api/v1/tenant/reports/income-statement/ledger?account_id='.$account->id.'&from=2026-01-01&to=2026-12-31')
        ->assertOk()->assertJsonPath('data.0.credit', '42.10');
    $fields = ['gl_code' => $account->gl_code, 'name' => $account->name, 'account_type' => 'INCOME', 'normal_balance' => 'CR'];
    $this->putJson('/api/v1/tenant/chart-of-accounts/'.$account->id, $fields + ['income_statement_line' => 'staff_costs'])
        ->assertUnprocessable()->assertJsonValidationErrors('income_statement_line');
    $this->putJson('/api/v1/tenant/chart-of-accounts/'.$account->id, $fields + ['income_statement_line' => 'fee_income'])
        ->assertOk()->assertJsonPath('data.income_statement_line', 'fee_income');
    expect(collect(isReport()['rows'])->firstWhere('key', 'fee_income')['amount'])->toBe('42.10');
});

it('does not count receipt of accrued interest a second time and reconciles to existing reports', function () {
    $income = isAccount();
    $receivable = isAccount('ASSET', null);
    isPost($income, '2026-01-01', '0', '100');
    isPost($receivable, '2026-02-01', '0', '100');
    $result = isReport();
    $trial = app(TrialBalanceServiceInterface::class)
        ->forPeriod(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));
    $movement = collect($trial['accounts'])->firstWhere('id', $income->id);
    expect($result['totals']['surplus'])->toBe('100.00')
        ->and($movement['period_credit'] - $movement['period_debit'])->toBe(100.0);
    $sheet = app(BalanceSheetServiceInterface::class)->generate(Carbon::parse('2026-12-31'));
    $equity = collect($sheet['sections'])->firstWhere('key', 'equity');
    expect(collect($equity['lines'])->firstWhere('name', 'Surplus / (Deficit) – Current Year')['amount'])->toBe(100.0);
});

it('keeps large decimal aggregates exact and shows an empty period without creating records', function () {
    $empty = isReport('2020-01-01', '2020-01-31');
    expect($empty['diagnostics']['has_movements'])->toBeFalse()->and($empty['totals']['surplus'])->toBe('0.00');
    $account = isAccount();
    for ($i = 0; $i < 12; $i++) {
        isPost($account, '2026-01-01', '0', '9999999999999.99');
    }
    expect(isReport()['totals']['surplus'])->toBe('119999999999999.88');
});

it('honours an explicit authorized branch and refuses branch injection outside access', function () {
    $account = isAccount();
    isPost($account, '2026-01-01', '0', '10', ['branch_id' => 1]);
    isPost($account, '2026-01-01', '0', '20', ['branch_id' => 2]);
    $query = ['from' => '2026-01-01', 'to' => '2026-12-31', 'branch_id' => 2];
    $response = app(IncomeStatementController::class)->index(Request::create('/', 'GET', $query))->getData(true);
    expect($response['totals']['surplus'])->toBe('20.00')->and($response['scope']['selected_branch_id'])->toBe(2);
    $staff = Staff::findOrFail(1);
    $staff->is_tenant_admin = false;
    $staff->branch_id = 1;
    $staff->status = 'active';
    $this->actingAs($staff, 'web');
    $this->actingAs($staff, 'tenant');
    $this->getJson('/api/v1/tenant/reports/income-statement?'.http_build_query($query))->assertForbidden();
    $this->getJson('/api/v1/tenant/reports/income-statement/ledger?'.http_build_query($query + ['account_id' => $account->id]))->assertForbidden();
});
