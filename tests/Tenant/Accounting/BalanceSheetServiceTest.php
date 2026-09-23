<?php

use App\Tenant\Modules\Accounting\Contracts\BalanceSheetServiceInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Settings\Models\FinancialYear;
use Carbon\Carbon;

function bsAccount(array $attrs, ?ChartOfAccount $parent = null): ChartOfAccount
{
    return ChartOfAccount::create(array_merge([
        'is_control' => false, 'is_postable' => true, 'is_active' => true, 'level' => 4,
        'parent_id' => $parent?->id,
    ], $attrs));
}

/** @param array<int, array{0: ChartOfAccount, 1: float, 2: float}> $lines */
function bsPost(string $date, array $lines): void
{
    $je = JournalEntry::create([
        'entry_no' => 'JE-BS-'.uniqid(), 'date' => $date,
        'period_date' => $date, 'fiscal_period' => substr($date, 0, 7),
        'reference' => 'BS-TEST', 'narration' => 'Balance sheet test',
        'journal_type' => 'MANUAL', 'status' => 'posted',
        'is_system' => true, 'posted_at' => now(),
    ]);

    foreach ($lines as [$account, $debit, $credit]) {
        GeneralLedger::create([
            'account_id' => $account->id, 'journal_entry_id' => $je->id, 'date' => $date,
            'debit' => $debit, 'credit' => $credit, 'balance' => 0, 'narration' => 'test',
        ]);
    }
}

function bsFind(array $lines, string $name): ?array
{
    foreach ($lines as $line) {
        if ($line['name'] === $name || $line['gl_code'] === $name) {
            return $line;
        }
        if ($found = bsFind($line['children'], $name)) {
            return $found;
        }
    }

    return null;
}

function bsSection(array $result, string $key): array
{
    return collect($result['sections'])->firstWhere('key', $key);
}

beforeEach(function () {
    $assets = bsAccount(['gl_code' => '10000', 'name' => 'ASSETS', 'account_type' => 'ASSET', 'account_subtype' => 'Header', 'normal_balance' => 'DR', 'level' => 1, 'is_postable' => false, 'is_control' => true]);
    $current = bsAccount(['gl_code' => '11000', 'name' => 'Current Assets', 'account_type' => 'ASSET', 'account_subtype' => 'Current Asset', 'normal_balance' => 'DR', 'level' => 2, 'is_postable' => false, 'is_control' => true], $assets);
    $this->cash = bsAccount(['gl_code' => '11101', 'name' => 'Cash at Bank', 'account_type' => 'ASSET', 'account_subtype' => 'Bank', 'normal_balance' => 'DR'], $current);
    $this->pettyCash = bsAccount(['gl_code' => '11102', 'name' => 'Petty Cash', 'account_type' => 'ASSET', 'account_subtype' => 'Cash', 'normal_balance' => 'DR'], $current);
    $this->loans = bsAccount(['gl_code' => '11301', 'name' => 'Personal Loans', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR'], $current);
    $this->provision = bsAccount(['gl_code' => '11401', 'name' => 'Loan Loss Provision', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR'], $current);

    $liabilities = bsAccount(['gl_code' => '20000', 'name' => 'LIABILITIES', 'account_type' => 'LIABILITY', 'account_subtype' => 'Header', 'normal_balance' => 'CR', 'level' => 1, 'is_postable' => false, 'is_control' => true]);
    $currentLiab = bsAccount(['gl_code' => '21000', 'name' => 'Current Liabilities', 'account_type' => 'LIABILITY', 'account_subtype' => 'Current Liability', 'normal_balance' => 'CR', 'level' => 2, 'is_postable' => false, 'is_control' => true], $liabilities);
    $this->savings = bsAccount(['gl_code' => '21101', 'name' => 'Member Savings', 'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit', 'normal_balance' => 'CR'], $currentLiab);

    $equity = bsAccount(['gl_code' => '30000', 'name' => 'EQUITY', 'account_type' => 'EQUITY', 'account_subtype' => 'Header', 'normal_balance' => 'CR', 'level' => 1, 'is_postable' => false, 'is_control' => true]);
    $shareCapital = bsAccount(['gl_code' => '31000', 'name' => 'Share Capital', 'account_type' => 'EQUITY', 'account_subtype' => 'Share Capital', 'normal_balance' => 'CR', 'level' => 2, 'is_postable' => false, 'is_control' => true], $equity);
    $this->shares = bsAccount(['gl_code' => '31100', 'name' => 'Ordinary Share Capital', 'account_type' => 'EQUITY', 'account_subtype' => 'Share Capital', 'normal_balance' => 'CR', 'level' => 3], $shareCapital);
    bsAccount(['gl_code' => '33000', 'name' => 'Retained Earnings / Surplus', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'CR', 'level' => 2, 'is_postable' => false, 'is_control' => true], $equity);

    $this->interest = bsAccount(['gl_code' => '41100', 'name' => 'Interest on Loans', 'account_type' => 'INCOME', 'account_subtype' => 'Loan Interest', 'normal_balance' => 'CR']);
    $this->ecl = bsAccount(['gl_code' => '51300', 'name' => 'ECL Provision Expense', 'account_type' => 'EXPENSE', 'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR']);
    $this->salaries = bsAccount(['gl_code' => '52100', 'name' => 'Salaries', 'account_type' => 'EXPENSE', 'account_subtype' => 'Staff Cost', 'normal_balance' => 'DR']);

    FinancialYear::create(['name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

    // FY2025 (no financial_years row): deposits, shares, a loan, interest income
    bsPost('2025-06-01', [[$this->cash, 100000, 0], [$this->savings, 0, 100000]]);
    bsPost('2025-07-01', [[$this->cash, 50000, 0], [$this->shares, 0, 50000]]);
    bsPost('2025-08-01', [[$this->loans, 80000, 0], [$this->cash, 0, 80000]]);
    bsPost('2025-11-01', [[$this->cash, 10000, 0], [$this->interest, 0, 10000]]);
    // FY2026: provision, interest, salaries
    bsPost('2026-03-01', [[$this->ecl, 4000, 0], [$this->provision, 0, 4000]]);
    bsPost('2026-04-01', [[$this->cash, 15000, 0], [$this->interest, 0, 15000]]);
    bsPost('2026-05-01', [[$this->salaries, 6000, 0], [$this->cash, 0, 6000]]);

    $this->service = app(BalanceSheetServiceInterface::class);
});

it('balances at both the as-at and comparison dates', function () {
    $result = $this->service->generate(Carbon::parse('2026-09-30'));

    expect($result['compare_to'])->toBe('2025-12-31');
    expect($result['totals']['current'])->toMatchArray([
        'total_assets' => 165000.0,
        'total_liabilities' => 100000.0,
        'total_equity' => 65000.0,
        'total_liabilities_and_equity' => 165000.0,
        'difference' => 0.0,
        'is_balanced' => true,
    ]);
    expect($result['totals']['compare'])->toMatchArray([
        'total_assets' => 160000.0,
        'total_liabilities' => 100000.0,
        'total_equity' => 60000.0,
        'is_balanced' => true,
    ]);
});

it('flattens level-1 headers and subtracts contra accounts', function () {
    $assets = bsSection($this->service->generate(Carbon::parse('2026-09-30')), 'assets');

    expect($assets['label'])->toBe('Assets');
    expect($assets['lines'][0]['gl_code'])->toBe('11000');
    expect($assets['lines'][0]['amount'])->toEqual(165000.0);
    expect(bsFind($assets['lines'], '11401')['amount'])->toEqual(-4000.0);
    expect(bsFind($assets['lines'], '11101')['amount'])->toEqual(89000.0);
    expect(bsFind($assets['lines'], '11101')['compare_amount'])->toEqual(80000.0);
});

it('splits the computed surplus at the financial year start', function () {
    $equity = bsSection($this->service->generate(Carbon::parse('2026-09-30')), 'equity');
    $retained = bsFind($equity['lines'], '33000');

    $currentYear = bsFind($retained['children'], 'Surplus / (Deficit) – Current Year');
    $priorYears = bsFind($retained['children'], 'Retained Surplus – Prior Years (unclosed)');

    expect($currentYear['is_computed'])->toBeTrue();
    expect($currentYear['id'])->toBeNull();
    expect($currentYear['amount'])->toEqual(5000.0);          // 15,000 − 4,000 − 6,000
    expect($currentYear['compare_amount'])->toEqual(10000.0); // calendar 2025 fallback
    expect($priorYears['amount'])->toEqual(10000.0);
    expect($priorYears['compare_amount'])->toEqual(0.0);
    expect($retained['amount'])->toEqual(15000.0);
});

it('honours an explicit comparison date', function () {
    $result = $this->service->generate(Carbon::parse('2026-09-30'), Carbon::parse('2025-07-15'));

    expect($result['compare_to'])->toBe('2025-07-15');
    expect($result['totals']['compare']['total_assets'])->toEqual(150000.0);
    expect($result['totals']['compare']['is_balanced'])->toBeTrue();
});

it('defaults the comparison date to the prior calendar year end without a financial year', function () {
    expect($this->service->defaultCompareDate(Carbon::parse('2027-03-01'))->toDateString())->toBe('2026-12-31');
    expect($this->service->defaultCompareDate(Carbon::parse('2026-09-30'))->toDateString())->toBe('2025-12-31');
});

it('prunes zero lines but always keeps computed lines', function () {
    $pruned = bsSection($this->service->generate(Carbon::parse('2026-09-30')), 'assets');
    $full = bsSection($this->service->generate(Carbon::parse('2026-09-30'), null, false), 'assets');

    expect(bsFind($pruned['lines'], '11102'))->toBeNull();
    expect(bsFind($full['lines'], '11102'))->not->toBeNull();

    $empty = $this->service->generate(Carbon::parse('2024-01-31'));
    $retained = bsFind(bsSection($empty, 'equity')['lines'], '33000');
    expect($retained['children'])->toHaveCount(2);
    expect($empty['totals']['current']['is_balanced'])->toBeTrue();
    expect($empty['financial_year'])->toBeNull();
});
