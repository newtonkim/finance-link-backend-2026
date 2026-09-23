# Balance Sheet Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the "Balance Sheet Coming Soon" page at `/tenant/reports/balance-sheet` with a comparative Statement of Financial Position backed by a new tenant API endpoint.

**Architecture:** A Laravel `BalanceSheetService` reuses `TrialBalanceServiceInterface::asOfDate()` for cumulative balances, builds an ASSET/LIABILITY/EQUITY tree from `chart_of_accounts.parent_id`, adds calculated surplus lines (there is no year-end closing) and exposes `GET /reports/balance-sheet`. The Vue 3 frontend flattens the tree into `StatementRow[]` with a pure function. The table and the CSV/Excel/PDF exports all render that list.

**Tech Stack:** Laravel 12, Pest (tests run on MySQL via `TenantTestCase`), Vue 3 + TypeScript, Tailwind 4, Vitest + @vue/test-utils, `xlsx`, `jspdf` + `jspdf-autotable`, `lucide-vue-next`.

**Spec:** `docs/superpowers/specs/2026-09-23-balance-sheet-design.md`

## Continuation status (2026-09-23)

Tasks 1–3 were implemented in the existing branch. Tasks 4–7 are now implemented:
shared accessible ledger drawer, statement rows and KPIs, CSV/Excel/PDF exports,
and the balance-sheet route/page. Drawer retries do not skip failed pages, and
out-of-order requests cannot overwrite newer report or drawer results.

The backend also fixes double-counting promoted children when a level-one header
has a direct posting. A regression test covers this case.

Validation: 11 backend Pest tests passed (54 assertions) against mfukopro_test;
19 frontend report tests passed, including real workbook/PDF generation and CSV
content checks. Production build, Vue TypeScript checking, targeted ESLint, Pint,
and whitespace checks passed. The lint/test configs now exclude the local pnpm
store; Vue lint discovery is limited to src to avoid recursive cache links.

Authenticated browser review, downloaded-file visual inspection, and production
deployment have not been performed. The detailed original checklist below is
retained as the implementation recipe, not a completion tracker.

## Global Constraints

- Two repos. Backend: `/Users/mcash-venom/Mcash/finance-link-backend-2026` (branch `feature/balance-sheet`, already created from `main`). Frontend: `/Users/mcash-venom/Mcash/finance-link-frontend-2026` (create branch `feature/balance-sheet` from `main` in Task 3).
- Report is **consolidated only**. Do not read or filter by branch.
- Sign rule is by section: ASSET `amount = debit − credit`; LIABILITY/EQUITY `amount = credit − debit`. Never flip by `normal_balance`.
- Computed surplus lines are labelled exactly `Surplus / (Deficit) – Current Year` and `Retained Surplus – Prior Years (unclosed)` (en dash `–`).
- Default `compare_to` = day before the start of the financial year containing `as_at`. If there is none, 31 Dec of the previous year.
- `is_balanced` is true when `abs(round(difference, 2)) < 0.005`.
- Money display: negatives in parentheses `(240,000.00)`, zero shown as `—`, `tabular-nums`, right-aligned.
- Every class that has a light style also needs its `dark:` variant. Use existing tokens (`nfuko-primary`, neutral palette).
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Backend: run `vendor/bin/pint --dirty` before each backend commit.

## File Map

**Backend (create)**
- `app/Tenant/Modules/Accounting/Contracts/BalanceSheetServiceInterface.php`: service contract
- `app/Tenant/Modules/Accounting/Services/BalanceSheetService.php`: tree building, signs, surplus, pruning, totals
- `app/Tenant/Http/Resources/BalanceSheetLineResource.php`: recursive 2dp rounding
- `app/Tenant/Http/Controllers/Api/V1/BalanceSheetController.php`: validation + response
- `tests/Tenant/Accounting/BalanceSheetServiceTest.php`
- `tests/Tenant/Accounting/BalanceSheetControllerTest.php`

**Backend (modify)**
- `app/Providers/AppServiceProvider.php` (~line 183): bind the interface
- `routes/tenant_api.php` (import near line 57; route after line 124)

**Frontend (create, under `src/tenant/`)**
- `apis/reports/balanceSheetApi.ts`: client + types
- `modules/reports/utils/accountingFormat.ts`: number and date formatting
- `modules/reports/utils/balanceSheetRows.ts`: tree to `StatementRow[]`
- `modules/reports/composables/useBalanceSheet.ts`: page state
- `modules/reports/composables/useBalanceSheetExport.ts`: CSV/Excel/PDF
- `modules/reports/components/BalanceSheetRow.vue`: one table row
- `modules/reports/components/BalanceSheetKpis.vue`: KPI cards
- `modules/reports/components/LedgerDrillDownDrawer.vue`: shared ledger drawer
- `modules/reports/pages/BalanceSheet.vue`: page
- `modules/reports/__tests__/fixtures/balanceSheet.ts`, `accountingFormat.spec.ts`, `balanceSheetRows.spec.ts`, `useBalanceSheet.spec.ts`, `BalanceSheetRow.spec.ts`

**Frontend (modify)**
- `layouts/routes.ts:351-355`: balance-sheet component
- `modules/reports/composables/useTrialBalance.ts`, `modules/reports/pages/TrialBalance.vue`: use the extracted drawer

---

### Task 1: BalanceSheetService (backend core)

**Files:**
- Create: `app/Tenant/Modules/Accounting/Contracts/BalanceSheetServiceInterface.php`
- Create: `app/Tenant/Modules/Accounting/Services/BalanceSheetService.php`
- Modify: `app/Providers/AppServiceProvider.php` (next to the `TrialBalanceServiceInterface` binding, ~line 183)
- Test: `tests/Tenant/Accounting/BalanceSheetServiceTest.php`

**Interfaces:**
- Consumes: `TrialBalanceServiceInterface::asOfDate(Carbon): array`. Its `accounts[]` rows have `id`, `closing_debit`, `closing_credit`.
- Produces:
  - `BalanceSheetServiceInterface::generate(Carbon $asAt, ?Carbon $compareTo = null, bool $hideZero = true): array` returning `{as_at, compare_to, financial_year: {name,start_date,end_date}|null, generated_at, sections: [{key,label,total,compare_total,lines}], totals: {current:{...}, compare:{...}}}`. Each line is `{id:int|null, gl_code:string|null, name, level:int|null, is_postable:bool, is_computed:bool, amount:float, compare_amount:float, children:line[]}` with **unrounded** line amounts. Section totals and `totals` are rounded to 2dp.
  - `BalanceSheetServiceInterface::defaultCompareDate(Carbon $asAt): Carbon`

- [ ] **Step 1: Write the failing tests**

Create `tests/Tenant/Accounting/BalanceSheetServiceTest.php`:

```php
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
```

Check the `2025-07-15` comparison: cash 100,000 + 50,000 = 150,000; savings 100,000; shares 50,000; surplus 0. That gives 150,000 = 150,000.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Tenant/Accounting/BalanceSheetServiceTest.php`
Expected: FAIL with `Target [App\Tenant\Modules\Accounting\Contracts\BalanceSheetServiceInterface] is not instantiable` or a class-not-found error.

- [ ] **Step 3: Create the interface**

`app/Tenant/Modules/Accounting/Contracts/BalanceSheetServiceInterface.php`:

```php
<?php

namespace App\Tenant\Modules\Accounting\Contracts;

use Carbon\Carbon;

interface BalanceSheetServiceInterface
{
    /**
     * Comparative Statement of Financial Position.
     *
     * Sections are built from the chart of accounts hierarchy (parent_id). Because
     * no year-end closing exists, income/expense balances are surfaced as computed
     * surplus lines under Retained Earnings so that Assets = Liabilities + Equity.
     */
    public function generate(Carbon $asAt, ?Carbon $compareTo = null, bool $hideZero = true): array;

    /**
     * Day before the start of the financial year containing $asAt,
     * or 31 Dec of the previous year when no financial year matches.
     */
    public function defaultCompareDate(Carbon $asAt): Carbon;
}
```

- [ ] **Step 4: Create the service**

`app/Tenant/Modules/Accounting/Services/BalanceSheetService.php`:

```php
<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Contracts\BalanceSheetServiceInterface;
use App\Tenant\Modules\Accounting\Contracts\TrialBalanceServiceInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Settings\Models\FinancialYear;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BalanceSheetService implements BalanceSheetServiceInterface
{
    /** account_type => [section key, label, sign applied to (debit − credit)] */
    private const SECTIONS = [
        'ASSET' => ['assets', 'Assets', 1],
        'LIABILITY' => ['liabilities', 'Liabilities', -1],
        'EQUITY' => ['equity', "Equity / Members' Funds", -1],
    ];

    public const CURRENT_YEAR_LABEL = 'Surplus / (Deficit) – Current Year';

    public const PRIOR_YEARS_LABEL = 'Retained Surplus – Prior Years (unclosed)';

    public function __construct(
        private readonly TrialBalanceServiceInterface $trialBalance,
    ) {}

    public function generate(Carbon $asAt, ?Carbon $compareTo = null, bool $hideZero = true): array
    {
        $compareTo ??= $this->defaultCompareDate($asAt);

        $current = $this->netBalances($asAt);
        $compare = $this->netBalances($compareTo);

        $accounts = ChartOfAccount::on('tenant')
            ->whereIn('account_type', array_keys(self::SECTIONS))
            ->orderBy('gl_code')
            ->get();

        $sections = [];
        foreach (self::SECTIONS as $type => [$key, $label, $sign]) {
            $typeAccounts = $accounts->where('account_type', $type)->values();
            $virtual = $type === 'EQUITY'
                ? $this->surplusLines($typeAccounts, $asAt, $compareTo)
                : [];

            $lines = $this->buildTree($typeAccounts, $sign, $current, $compare, $virtual);
            if ($hideZero) {
                $lines = $this->prune($lines);
            }

            $sections[] = [
                'key' => $key,
                'label' => $label,
                'total' => round(array_sum(array_column($lines, 'amount')), 2),
                'compare_total' => round(array_sum(array_column($lines, 'compare_amount')), 2),
                'lines' => $lines,
            ];
        }

        $financialYear = $this->financialYearFor($asAt);

        return [
            'as_at' => $asAt->toDateString(),
            'compare_to' => $compareTo->toDateString(),
            'financial_year' => $financialYear ? [
                'name' => $financialYear->name,
                'start_date' => Carbon::parse($financialYear->start_date)->toDateString(),
                'end_date' => Carbon::parse($financialYear->end_date)->toDateString(),
            ] : null,
            'generated_at' => now()->toIso8601String(),
            'sections' => $sections,
            'totals' => [
                'current' => $this->totals($sections, 'total'),
                'compare' => $this->totals($sections, 'compare_total'),
            ],
        ];
    }

    public function defaultCompareDate(Carbon $asAt): Carbon
    {
        $financialYear = $this->financialYearFor($asAt);

        return $financialYear
            ? Carbon::parse($financialYear->start_date)->subDay()->startOfDay()
            : Carbon::create($asAt->year - 1, 12, 31)->startOfDay();
    }

    /** @return array<int, float> account_id => debit − credit (cumulative to $date) */
    private function netBalances(Carbon $date): array
    {
        $rows = $this->trialBalance->asOfDate($date)['accounts'];

        $net = [];
        foreach ($rows as $row) {
            $net[$row['id']] = (float) $row['closing_debit'] - (float) $row['closing_credit'];
        }

        return $net;
    }

    private function financialYearFor(Carbon $date): ?FinancialYear
    {
        return FinancialYear::on('tenant')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->orderByDesc('start_date')
            ->first();
    }

    private function financialYearStart(Carbon $date): Carbon
    {
        $financialYear = $this->financialYearFor($date);

        return $financialYear
            ? Carbon::parse($financialYear->start_date)->startOfDay()
            : Carbon::create($date->year, 1, 1)->startOfDay();
    }

    /** Σ(credit − debit) over INCOME + EXPENSE accounts = income − expenses. */
    private function surplus(?Carbon $from, Carbon $to): float
    {
        $query = DB::connection('tenant')
            ->table('general_ledger as gl')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'gl.account_id')
            ->whereIn('coa.account_type', ['INCOME', 'EXPENSE'])
            ->where('gl.date', '<=', $to->toDateString());

        if ($from) {
            $query->where('gl.date', '>=', $from->toDateString());
        }

        return (float) $query->sum(DB::raw('gl.credit - gl.debit'));
    }

    /**
     * Two computed lines. They sit under the Retained Earnings group, falling back
     * to the equity level-1 header, then to the section root.
     */
    private function surplusLines(Collection $equityAccounts, Carbon $asAt, Carbon $compareTo): array
    {
        $parent = $equityAccounts->first(fn ($a) => $a->account_subtype === 'Retained Earnings' && (int) $a->level === 2)
            ?? $equityAccounts->first(fn ($a) => (int) $a->level === 1 && ! $a->is_postable);

        $split = function (Carbon $date): array {
            $fyStart = $this->financialYearStart($date);

            return [
                'current' => $this->surplus($fyStart, $date),
                'prior' => $this->surplus(null, $fyStart->copy()->subDay()),
            ];
        };

        $now = $split($asAt);
        $then = $split($compareTo);

        return [
            $this->virtualNode('computed:current-year', self::CURRENT_YEAR_LABEL, $parent?->id, $now['current'], $then['current']),
            $this->virtualNode('computed:prior-years', self::PRIOR_YEARS_LABEL, $parent?->id, $now['prior'], $then['prior']),
        ];
    }

    private function virtualNode(string $key, string $name, ?int $parentId, float $amount, float $compare): array
    {
        return [
            'key' => $key, 'parent_id' => $parentId,
            'id' => null, 'gl_code' => null, 'name' => $name, 'level' => null,
            'is_postable' => false, 'is_computed' => true,
            'own' => $amount, 'own_compare' => $compare,
        ];
    }

    private function buildTree(Collection $accounts, int $sign, array $current, array $compare, array $virtual): array
    {
        $ids = array_flip($accounts->pluck('id')->all());
        $children = [];
        $roots = [];

        $nodes = $accounts->map(fn (ChartOfAccount $a) => [
            'key' => $a->id, 'parent_id' => $a->parent_id,
            'id' => $a->id, 'gl_code' => $a->gl_code, 'name' => $a->name, 'level' => (int) $a->level,
            'is_postable' => (bool) $a->is_postable, 'is_computed' => false,
            // Own balance for every account (even headers) so nothing is lost if a header was posted to.
            'own' => $sign * ($current[$a->id] ?? 0.0),
            'own_compare' => $sign * ($compare[$a->id] ?? 0.0),
        ])->all();

        foreach ([...$nodes, ...$virtual] as $node) {
            if ($node['parent_id'] !== null && isset($ids[$node['parent_id']])) {
                $children[$node['parent_id']][] = $node;
            } else {
                $roots[] = $node;
            }
        }

        // Level-1 headers (ASSETS, LIABILITIES, EQUITY) are the section itself: promote their children.
        $top = [];
        foreach ($roots as $root) {
            if (! $root['is_computed'] && ! $root['is_postable'] && $root['level'] === 1) {
                array_push($top, ...($children[$root['key']] ?? []));
                // Keep any stray balance posted directly to the header.
                if (round($root['own'], 2) != 0 || round($root['own_compare'], 2) != 0) {
                    $top[] = $root;
                }
            } else {
                $top[] = $root;
            }
        }

        return array_map(fn (array $node) => $this->resolve($node, $children), $top);
    }

    private function resolve(array $node, array $children): array
    {
        $kids = array_map(
            fn (array $child) => $this->resolve($child, $children),
            $node['is_computed'] ? [] : ($children[$node['key']] ?? []),
        );

        return [
            'id' => $node['id'],
            'gl_code' => $node['gl_code'],
            'name' => $node['name'],
            'level' => $node['level'],
            'is_postable' => $node['is_postable'],
            'is_computed' => $node['is_computed'],
            'amount' => $node['own'] + array_sum(array_column($kids, 'amount')),
            'compare_amount' => $node['own_compare'] + array_sum(array_column($kids, 'compare_amount')),
            'children' => $kids,
        ];
    }

    private function prune(array $lines): array
    {
        $kept = [];
        foreach ($lines as $line) {
            $line['children'] = $this->prune($line['children']);

            if ($line['is_computed']
                || $line['children'] !== []
                || round($line['amount'], 2) != 0
                || round($line['compare_amount'], 2) != 0) {
                $kept[] = $line;
            }
        }

        return $kept;
    }

    private function totals(array $sections, string $field): array
    {
        $bySection = array_column($sections, $field, 'key');
        $assets = round($bySection['assets'], 2);
        $liabilities = round($bySection['liabilities'], 2);
        $equity = round($bySection['equity'], 2);
        $difference = round($assets - ($liabilities + $equity), 2);

        return [
            'total_assets' => $assets,
            'total_liabilities' => $liabilities,
            'total_equity' => $equity,
            'total_liabilities_and_equity' => round($liabilities + $equity, 2),
            'difference' => $difference,
            'is_balanced' => abs($difference) < 0.005,
        ];
    }
}
```

`difference` can come out as `-0.0` in PHP, which `toMatchArray([... 'difference' => 0.0])` still matches (`-0.0 == 0.0`). If Pest compares strictly and fails, change the return to `'difference' => $difference + 0.0`.

- [ ] **Step 5: Bind the interface**

In `app/Providers/AppServiceProvider.php`, directly after the existing `TrialBalanceServiceInterface` binding (~line 183–185), add the same style of binding:

```php
        $this->app->bind(
            \App\Tenant\Modules\Accounting\Contracts\BalanceSheetServiceInterface::class,
            \App\Tenant\Modules\Accounting\Services\BalanceSheetService::class
        );
```

Match the exact method (`bind` / `singleton`) used by the neighbouring TrialBalance binding.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Tenant/Accounting/BalanceSheetServiceTest.php tests/Tenant/Accounting/TrialBalanceServiceTest.php`
Expected: all PASS.

- [ ] **Step 7: Lint and commit**

```bash
vendor/bin/pint --dirty
git add app/Tenant/Modules/Accounting/Contracts/BalanceSheetServiceInterface.php app/Tenant/Modules/Accounting/Services/BalanceSheetService.php app/Providers/AppServiceProvider.php tests/Tenant/Accounting/BalanceSheetServiceTest.php
git commit -m "feat(accounting): add balance sheet service

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Balance sheet endpoint

**Files:**
- Create: `app/Tenant/Http/Resources/BalanceSheetLineResource.php`
- Create: `app/Tenant/Http/Controllers/Api/V1/BalanceSheetController.php`
- Modify: `routes/tenant_api.php` (import next to `use ...TrialBalanceController;` ~line 57; route after line 124)
- Test: `tests/Tenant/Accounting/BalanceSheetControllerTest.php`

**Interfaces:**
- Consumes: `BalanceSheetServiceInterface::generate()` (Task 1).
- Produces: `GET /api/v1/tenant/reports/balance-sheet?as_at=YYYY-MM-DD&compare_to=YYYY-MM-DD&hide_zero=1|0`, returning the Task 1 shape with every line's `amount` and `compare_amount` rounded to 2dp.

- [ ] **Step 1: Write the failing tests**

`tests/Tenant/Accounting/BalanceSheetControllerTest.php`:

```php
<?php

use App\Tenant\Http\Controllers\Api\V1\BalanceSheetController;
use App\Tenant\Modules\Accounting\Contracts\BalanceSheetServiceInterface;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

function bsCall(array $query): array
{
    $response = app(BalanceSheetController::class)->index(Request::create('/reports/balance-sheet', 'GET', $query));

    return $response->getData(true);
}

it('rejects a comparison date after the as-at date', function () {
    bsCall(['as_at' => '2026-01-31', 'compare_to' => '2026-02-01']);
})->throws(ValidationException::class);

it('rejects an invalid date', function () {
    bsCall(['as_at' => 'not-a-date']);
})->throws(ValidationException::class);

it('accepts compare_to without as_at by defaulting as_at to today', function () {
    $data = bsCall(['compare_to' => Carbon::today()->subYear()->toDateString()]);

    expect($data['as_at'])->toBe(Carbon::today()->toDateString());
});

it('passes parsed params to the service and rounds line amounts', function () {
    $fake = Mockery::mock(BalanceSheetServiceInterface::class);
    $fake->shouldReceive('generate')
        ->withArgs(fn (Carbon $asAt, ?Carbon $compareTo, bool $hideZero) => $asAt->toDateString() === '2026-09-30'
            && $compareTo?->toDateString() === '2025-12-31'
            && $hideZero === false)
        ->once()
        ->andReturn([
            'as_at' => '2026-09-30', 'compare_to' => '2025-12-31', 'financial_year' => null,
            'generated_at' => 'x', 'totals' => ['current' => [], 'compare' => []],
            'sections' => [[
                'key' => 'assets', 'label' => 'Assets', 'total' => 1.0, 'compare_total' => 0.0,
                'lines' => [[
                    'id' => 1, 'gl_code' => '11000', 'name' => 'Current Assets', 'level' => 2,
                    'is_postable' => false, 'is_computed' => false,
                    'amount' => 1.004999, 'compare_amount' => 0.0,
                    'children' => [[
                        'id' => 2, 'gl_code' => '11101', 'name' => 'Cash', 'level' => 4,
                        'is_postable' => true, 'is_computed' => false,
                        'amount' => 1.004999, 'compare_amount' => 0.0, 'children' => [],
                    ]],
                ]],
            ]],
        ]);
    app()->instance(BalanceSheetServiceInterface::class, $fake);

    $data = bsCall(['as_at' => '2026-09-30', 'compare_to' => '2025-12-31', 'hide_zero' => '0']);

    expect($data['sections'][0]['lines'][0]['amount'])->toBe(1);
    expect($data['sections'][0]['lines'][0]['children'][0]['amount'])->toBe(1);
    expect($data['sections'][0]['lines'][0]['children'][0]['gl_code'])->toBe('11101');
});
```

`getData(true)` decodes JSON, so `1.0` becomes int `1`. That is why the test asserts `toBe(1)`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Tenant/Accounting/BalanceSheetControllerTest.php`
Expected: FAIL with `Class "App\Tenant\Http\Controllers\Api\V1\BalanceSheetController" not found`.

- [ ] **Step 3: Create the resource**

`app/Tenant/Http/Resources/BalanceSheetLineResource.php`:

```php
<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BalanceSheetLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'gl_code' => $this['gl_code'],
            'name' => $this['name'],
            'level' => $this['level'],
            'is_postable' => $this['is_postable'],
            'is_computed' => $this['is_computed'],
            'amount' => round($this['amount'], 2),
            'compare_amount' => round($this['compare_amount'], 2),
            'children' => self::collection(collect($this['children']))->resolve($request),
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

`app/Tenant/Http/Controllers/Api/V1/BalanceSheetController.php`:

```php
<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Http\Resources\BalanceSheetLineResource;
use App\Tenant\Modules\Accounting\Contracts\BalanceSheetServiceInterface;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BalanceSheetController extends Controller
{
    public function __construct(
        private readonly BalanceSheetServiceInterface $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // before_or_equal:as_at needs as_at present to compare against.
        $request->mergeIfMissing(['as_at' => Carbon::today()->toDateString()]);

        $validated = $request->validate([
            'as_at' => ['required', 'date'],
            'compare_to' => ['nullable', 'date', 'before_or_equal:as_at'],
            'hide_zero' => ['nullable', 'boolean'],
        ]);

        $result = $this->service->generate(
            Carbon::parse($validated['as_at']),
            isset($validated['compare_to']) ? Carbon::parse($validated['compare_to']) : null,
            $request->boolean('hide_zero', true),
        );

        $result['sections'] = array_map(fn (array $section) => [
            ...$section,
            'lines' => BalanceSheetLineResource::collection(collect($section['lines']))->resolve($request),
        ], $result['sections']);

        return response()->json($result);
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/tenant_api.php` add the import beside the TrialBalance one:

```php
use App\Tenant\Http\Controllers\Api\V1\BalanceSheetController;
```

and below the trial-balance routes (after `Route::get('reports/trial-balance/ledger', ...)`, inside the `feature:reports` group):

```php

    // ── Balance Sheet (Statement of Financial Position) ─────────────────────────
    Route::get('reports/balance-sheet', [BalanceSheetController::class, 'index']);
```

- [ ] **Step 6: Run tests and route check**

Run: `php artisan test tests/Tenant/Accounting/BalanceSheetControllerTest.php tests/Tenant/Accounting/BalanceSheetServiceTest.php`
Expected: all PASS.

Run: `php artisan route:list --path=balance-sheet`
Expected: one `GET|HEAD` row for `api/v1/tenant/reports/balance-sheet` → `BalanceSheetController@index`.

- [ ] **Step 7: Lint and commit**

```bash
vendor/bin/pint --dirty
git add app/Tenant/Http/Resources/BalanceSheetLineResource.php app/Tenant/Http/Controllers/Api/V1/BalanceSheetController.php routes/tenant_api.php tests/Tenant/Accounting/BalanceSheetControllerTest.php
git commit -m "feat(reports): expose balance sheet endpoint

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Frontend data layer (API types, formatting, row flattening, composable)

All paths below are relative to `/Users/mcash-venom/Mcash/finance-link-frontend-2026`.

**Files:**
- Create: `src/tenant/apis/reports/balanceSheetApi.ts`
- Create: `src/tenant/modules/reports/utils/accountingFormat.ts`
- Create: `src/tenant/modules/reports/utils/balanceSheetRows.ts`
- Create: `src/tenant/modules/reports/composables/useBalanceSheet.ts`
- Test: `src/tenant/modules/reports/__tests__/fixtures/balanceSheet.ts`, `accountingFormat.spec.ts`, `balanceSheetRows.spec.ts`, `useBalanceSheet.spec.ts`

**Interfaces:**
- Consumes: the Task 2 endpoint shape; `tenantClient` from `@/tenant/apis/tenantClient`; `formatMoneyValue` from `@/Global`.
- Produces:
  - Types `BalanceSheetLine`, `BalanceSheetSection`, `BalanceSheetTotals`, `BalanceSheetResponse`, `BalanceSheetParams`; `balanceSheetApi.getBalanceSheet(params): Promise<BalanceSheetResponse>`
  - `formatAccounting(v: number | null | undefined): string`, `percentChange(cur: number, prev: number): number | null`, `formatLongDate(iso: string): string` ("23 September 2026"), `formatShortDate(iso: string): string` ("23 Sep 2026")
  - `StatementRowKind`, `StatementRow`, `lineKey(line)`, `defaultExpandedKeys(result)`, `allExpandableKeys(result)`, `buildStatementRows(result, expanded)`
  - `useBalanceSheet({ autoLoad?: boolean })` returning `{ asAt, compareTo, hideZero, loading, error, result, expanded, rows, totals, isBalanced, kpis, drillRange, generate, toggle, expandAll, collapseAll }`

- [ ] **Step 1: Create the frontend branch**

```bash
cd /Users/mcash-venom/Mcash/finance-link-frontend-2026
git status --short   # must be empty
git switch main && git pull --ff-only && git switch -c feature/balance-sheet
```

- [ ] **Step 2: Create the API client and types**

`src/tenant/apis/reports/balanceSheetApi.ts`:

```ts
import { tenantClient } from '@/tenant/apis/tenantClient'

export interface BalanceSheetParams {
  as_at?: string
  compare_to?: string
  hide_zero?: 0 | 1
}

export interface BalanceSheetLine {
  id: number | null
  gl_code: string | null
  name: string
  level: number | null
  is_postable: boolean
  is_computed: boolean
  amount: number
  compare_amount: number
  children: BalanceSheetLine[]
}

export interface BalanceSheetSection {
  key: 'assets' | 'liabilities' | 'equity'
  label: string
  total: number
  compare_total: number
  lines: BalanceSheetLine[]
}

export interface BalanceSheetTotals {
  total_assets: number
  total_liabilities: number
  total_equity: number
  total_liabilities_and_equity: number
  difference: number
  is_balanced: boolean
}

export interface BalanceSheetResponse {
  as_at: string
  compare_to: string
  financial_year: { name: string; start_date: string; end_date: string } | null
  generated_at: string
  sections: BalanceSheetSection[]
  totals: { current: BalanceSheetTotals; compare: BalanceSheetTotals }
}

export const balanceSheetApi = {
  async getBalanceSheet(params: BalanceSheetParams): Promise<BalanceSheetResponse> {
    const res = await tenantClient.get('/reports/balance-sheet', { params })
    return res.data
  },
}
```

- [ ] **Step 3: Create the shared test fixture**

`src/tenant/modules/reports/__tests__/fixtures/balanceSheet.ts`:

```ts
import type { BalanceSheetLine, BalanceSheetResponse } from '@/tenant/apis/reports/balanceSheetApi'

export function line(partial: Partial<BalanceSheetLine> & { name: string }): BalanceSheetLine {
  return {
    id: null, gl_code: null, level: null, is_postable: false, is_computed: false,
    amount: 0, compare_amount: 0, children: [], ...partial,
  }
}

const cash = line({ id: 3, gl_code: '11101', name: 'Cash at Bank', level: 4, is_postable: true, amount: 89000, compare_amount: 80000 })
const provision = line({ id: 4, gl_code: '11401', name: 'Loan Loss Provision', level: 4, is_postable: true, amount: -4000, compare_amount: 0 })
const loans = line({ id: 5, gl_code: '11301', name: 'Personal Loans', level: 4, is_postable: true, amount: 80000, compare_amount: 80000 })
const currentAssets = line({ id: 2, gl_code: '11000', name: 'Current Assets', level: 2, amount: 165000, compare_amount: 160000, children: [cash, loans, provision] })
const savings = line({ id: 8, gl_code: '21101', name: 'Member Savings', level: 4, is_postable: true, amount: 100000, compare_amount: 100000 })
const currentLiab = line({ id: 7, gl_code: '21000', name: 'Current Liabilities', level: 2, amount: 100000, compare_amount: 100000, children: [savings] })
const shares = line({ id: 11, gl_code: '31100', name: 'Ordinary Share Capital', level: 3, is_postable: true, amount: 50000, compare_amount: 50000 })
const shareCapital = line({ id: 10, gl_code: '31000', name: 'Share Capital', level: 2, amount: 50000, compare_amount: 50000, children: [shares] })
const surplus = line({ name: 'Surplus / (Deficit) – Current Year', is_computed: true, amount: 5000, compare_amount: 10000 })
const prior = line({ name: 'Retained Surplus – Prior Years (unclosed)', is_computed: true, amount: 10000, compare_amount: 0 })
const retained = line({ id: 12, gl_code: '33000', name: 'Retained Earnings / Surplus', level: 2, amount: 15000, compare_amount: 10000, children: [surplus, prior] })

export function balanceSheetFixture(): BalanceSheetResponse {
  const totals = (assets: number, liabilities: number, equity: number) => ({
    total_assets: assets, total_liabilities: liabilities, total_equity: equity,
    total_liabilities_and_equity: liabilities + equity,
    difference: assets - liabilities - equity, is_balanced: assets === liabilities + equity,
  })

  return structuredClone({
    as_at: '2026-09-30',
    compare_to: '2025-12-31',
    financial_year: { name: 'FY2026', start_date: '2026-01-01', end_date: '2026-12-31' },
    generated_at: '2026-09-30T10:00:00+03:00',
    sections: [
      { key: 'assets', label: 'Assets', total: 165000, compare_total: 160000, lines: [currentAssets] },
      { key: 'liabilities', label: 'Liabilities', total: 100000, compare_total: 100000, lines: [currentLiab] },
      { key: 'equity', label: "Equity / Members' Funds", total: 65000, compare_total: 60000, lines: [shareCapital, retained] },
    ],
    totals: { current: totals(165000, 100000, 65000), compare: totals(160000, 100000, 60000) },
  }) as BalanceSheetResponse
}
```

- [ ] **Step 4: Write failing tests for formatting and rows**

`src/tenant/modules/reports/__tests__/accountingFormat.spec.ts`:

```ts
import { describe, expect, it, vi } from 'vitest'

vi.mock('@/Global', () => ({
  formatMoneyValue: (v: number | string) =>
    Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
}))

import { formatAccounting, formatLongDate, formatShortDate, percentChange } from '../utils/accountingFormat'

describe('accountingFormat', () => {
  it('formats positives plainly and negatives in parentheses', () => {
    expect(formatAccounting(165000)).toBe('165,000.00')
    expect(formatAccounting(-4000)).toBe('(4,000.00)')
  })

  it('renders zero (including rounding noise) as a dash and null as empty', () => {
    expect(formatAccounting(0)).toBe('—')
    expect(formatAccounting(-0.001)).toBe('—')
    expect(formatAccounting(null)).toBe('')
  })

  it('returns null percent change for a zero base', () => {
    expect(percentChange(100, 0)).toBeNull()
    expect(percentChange(110, 100)).toBeCloseTo(10)
    expect(percentChange(-50, -100)).toBeCloseTo(50)
  })

  it('formats dates without locale ambiguity', () => {
    expect(formatLongDate('2026-09-23')).toBe('23 September 2026')
    expect(formatShortDate('2026-09-23')).toBe('23 Sep 2026')
  })
})
```

`src/tenant/modules/reports/__tests__/balanceSheetRows.spec.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { allExpandableKeys, buildStatementRows, defaultExpandedKeys, lineKey } from '../utils/balanceSheetRows'
import { balanceSheetFixture } from './fixtures/balanceSheet'

describe('buildStatementRows', () => {
  it('shows only group lines when nothing is expanded', () => {
    const rows = buildStatementRows(balanceSheetFixture(), new Set())

    expect(rows.map(r => `${r.kind}:${r.label}`)).toEqual([
      'section:Assets',
      'line:Current Assets',
      'grand-total:Total Assets',
      'section:Liabilities',
      'line:Current Liabilities',
      'section-total:Total Liabilities',
      "section:Equity / Members' Funds",
      'line:Share Capital',
      'line:Retained Earnings / Surplus',
      "section-total:Total Equity / Members' Funds",
      'grand-total:Total Liabilities & Equity',
    ])
    expect(rows[1]).toMatchObject({ amount: 165000, compareAmount: 160000, hasChildren: true, expanded: false, depth: 1 })
    expect(rows.at(-1)).toMatchObject({ amount: 165000, compareAmount: 160000 })
  })

  it('expands a group into children followed by a subtotal, blanking the header amount', () => {
    const data = balanceSheetFixture()
    const rows = buildStatementRows(data, new Set([lineKey(data.sections[0]!.lines[0]!)]))

    const labels = rows.slice(1, 6).map(r => `${r.kind}:${r.label}`)
    expect(labels).toEqual([
      'line:Current Assets',
      'line:Cash at Bank',
      'line:Personal Loans',
      'line:Loan Loss Provision',
      'subtotal:Total Current Assets',
    ])
    expect(rows[1]).toMatchObject({ amount: null, compareAmount: null, expanded: true })
    expect(rows[2]).toMatchObject({ depth: 2, glCode: '11101', amount: 89000 })
    expect(rows[5]).toMatchObject({ depth: 1, amount: 165000 })
  })

  it('keys computed lines by name and collects expandable keys', () => {
    const data = balanceSheetFixture()
    const computed = data.sections[2]!.lines[1]!.children[0]!

    expect(lineKey(computed)).toBe('computed:Surplus / (Deficit) – Current Year')
    expect([...defaultExpandedKeys(data)].sort()).toEqual(['10', '12', '2', '7'])
    expect([...allExpandableKeys(data)].sort()).toEqual(['10', '12', '2', '7'])
  })
})
```

- [ ] **Step 5: Run to verify failure**

Run: `pnpm vitest run src/tenant/modules/reports/__tests__/accountingFormat.spec.ts src/tenant/modules/reports/__tests__/balanceSheetRows.spec.ts`
Expected: FAIL. The imports `../utils/accountingFormat` and `../utils/balanceSheetRows` cannot be resolved.

- [ ] **Step 6: Implement the formatting utilities**

`src/tenant/modules/reports/utils/accountingFormat.ts`:

```ts
import { formatMoneyValue } from '@/Global'

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December']

/** Accounting style: negatives in parentheses, zero as an em dash, null as blank. */
export function formatAccounting(value: number | null | undefined): string {
  if (value === null || value === undefined) return ''
  const rounded = Math.round(value * 100) / 100
  if (rounded === 0) return '—'
  const text = formatMoneyValue(Math.abs(rounded))
  return rounded < 0 ? `(${text})` : text
}

/** Percentage change from previous to current; null when there is no base to compare. */
export function percentChange(current: number, previous: number): number | null {
  if (!previous) return null
  return ((current - previous) / Math.abs(previous)) * 100
}

function parts(iso: string): [number, number, number] {
  const [y, m, d] = iso.slice(0, 10).split('-').map(Number)
  return [y ?? 0, (m ?? 1) - 1, d ?? 1]
}

export function formatLongDate(iso: string): string {
  const [y, m, d] = parts(iso)
  return `${d} ${MONTHS[m]} ${y}`
}

export function formatShortDate(iso: string): string {
  const [y, m, d] = parts(iso)
  return `${d} ${MONTHS[m]!.slice(0, 3)} ${y}`
}
```

- [ ] **Step 7: Implement row flattening**

`src/tenant/modules/reports/utils/balanceSheetRows.ts`:

```ts
import type { BalanceSheetLine, BalanceSheetResponse } from '@/tenant/apis/reports/balanceSheetApi'

export type StatementRowKind = 'section' | 'line' | 'subtotal' | 'section-total' | 'grand-total'

export interface StatementRow {
  key: string
  kind: StatementRowKind
  label: string
  glCode: string | null
  depth: number
  /** null = intentionally blank (section headings, expanded group headers) */
  amount: number | null
  compareAmount: number | null
  hasChildren: boolean
  expanded: boolean
  line: BalanceSheetLine | null
}

export function lineKey(line: BalanceSheetLine): string {
  return line.id !== null ? String(line.id) : `computed:${line.name}`
}

function totalRow(key: string, kind: StatementRowKind, label: string, amount: number, compareAmount: number, depth = 0): StatementRow {
  return { key, kind, label, glCode: null, depth, amount, compareAmount, hasChildren: false, expanded: false, line: null }
}

/** Top-level group lines start expanded, so subsections show their groups. */
export function defaultExpandedKeys(result: BalanceSheetResponse): Set<string> {
  const keys = new Set<string>()
  for (const section of result.sections) {
    for (const line of section.lines) {
      if (line.children.length) keys.add(lineKey(line))
    }
  }
  return keys
}

export function allExpandableKeys(result: BalanceSheetResponse): Set<string> {
  const keys = new Set<string>()
  const walk = (line: BalanceSheetLine) => {
    if (!line.children.length) return
    keys.add(lineKey(line))
    line.children.forEach(walk)
  }
  result.sections.forEach(s => s.lines.forEach(walk))
  return keys
}

export function buildStatementRows(result: BalanceSheetResponse, expanded: Set<string>): StatementRow[] {
  const rows: StatementRow[] = []

  const walk = (line: BalanceSheetLine, depth: number) => {
    const key = lineKey(line)
    const hasChildren = line.children.length > 0
    const isOpen = hasChildren && expanded.has(key)

    rows.push({
      key, kind: 'line', label: line.name, glCode: line.gl_code, depth,
      amount: isOpen ? null : line.amount,
      compareAmount: isOpen ? null : line.compare_amount,
      hasChildren, expanded: isOpen, line,
    })

    if (isOpen) {
      line.children.forEach(child => walk(child, depth + 1))
      rows.push(totalRow(`${key}:subtotal`, 'subtotal', `Total ${line.name}`, line.amount, line.compare_amount, depth))
    }
  }

  for (const section of result.sections) {
    rows.push({
      key: `section:${section.key}`, kind: 'section', label: section.label, glCode: null, depth: 0,
      amount: null, compareAmount: null, hasChildren: false, expanded: false, line: null,
    })
    section.lines.forEach(line => walk(line, 1))
    rows.push(totalRow(
      `section:${section.key}:total`,
      section.key === 'assets' ? 'grand-total' : 'section-total',
      `Total ${section.label}`,
      section.total,
      section.compare_total,
    ))
  }

  const { current, compare } = result.totals
  rows.push(totalRow('grand:liabilities-equity', 'grand-total', 'Total Liabilities & Equity',
    current.total_liabilities_and_equity, compare.total_liabilities_and_equity))

  return rows
}
```

- [ ] **Step 8: Run to verify pass**

Run: `pnpm vitest run src/tenant/modules/reports/__tests__/accountingFormat.spec.ts src/tenant/modules/reports/__tests__/balanceSheetRows.spec.ts`
Expected: PASS (7 tests).

- [ ] **Step 9: Write failing composable test**

`src/tenant/modules/reports/__tests__/useBalanceSheet.spec.ts`:

```ts
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/Global', () => ({ formatMoneyValue: (v: number | string) => String(v) }))

const getBalanceSheet = vi.fn()
vi.mock('@/tenant/apis/reports/balanceSheetApi', () => ({
  balanceSheetApi: { getBalanceSheet: (...args: unknown[]) => getBalanceSheet(...args) },
}))

import { useBalanceSheet } from '../composables/useBalanceSheet'
import { balanceSheetFixture } from './fixtures/balanceSheet'

describe('useBalanceSheet', () => {
  beforeEach(() => getBalanceSheet.mockReset())

  it('loads, adopts the server comparison date and expands top-level groups', async () => {
    getBalanceSheet.mockResolvedValue(balanceSheetFixture())
    const bs = useBalanceSheet({ autoLoad: false })
    bs.asAt.value = '2026-09-30'

    await bs.generate()

    expect(getBalanceSheet).toHaveBeenCalledWith({ as_at: '2026-09-30', compare_to: undefined, hide_zero: 1 })
    expect(bs.compareTo.value).toBe('2025-12-31')
    expect(bs.rows.value.some(r => r.label === 'Cash at Bank')).toBe(true)
    expect(bs.isBalanced.value).toBe(true)
    expect(bs.kpis.value.map(k => k.key)).toEqual(['assets', 'liabilities', 'equity'])
    expect(bs.kpis.value[0]!.change).toBeCloseTo(3.125)
    expect(bs.drillRange.value).toEqual({ from: '2026-01-01', to: '2026-09-30' })
  })

  it('toggles, expands and collapses groups', async () => {
    getBalanceSheet.mockResolvedValue(balanceSheetFixture())
    const bs = useBalanceSheet({ autoLoad: false })
    await bs.generate()

    bs.collapseAll()
    expect(bs.rows.value.some(r => r.label === 'Cash at Bank')).toBe(false)

    bs.toggle('2')
    expect(bs.rows.value.some(r => r.label === 'Cash at Bank')).toBe(true)
    bs.toggle('2')
    expect(bs.rows.value.some(r => r.label === 'Cash at Bank')).toBe(false)

    bs.expandAll()
    expect(bs.rows.value.some(r => r.label === 'Surplus / (Deficit) – Current Year')).toBe(true)
  })

  it('surfaces the API error message and clears the result', async () => {
    getBalanceSheet.mockRejectedValue({ response: { data: { message: 'The compare to field must be a date before or equal to as at.' } } })
    const bs = useBalanceSheet({ autoLoad: false })

    await bs.generate()

    expect(bs.error.value).toBe('The compare to field must be a date before or equal to as at.')
    expect(bs.result.value).toBeNull()
    expect(bs.loading.value).toBe(false)
  })
})
```

Check the assets change figure: (165,000 − 160,000) / 160,000 = 3.125%.

- [ ] **Step 10: Run to verify failure**

Run: `pnpm vitest run src/tenant/modules/reports/__tests__/useBalanceSheet.spec.ts`
Expected: FAIL. `../composables/useBalanceSheet` cannot be resolved.

- [ ] **Step 11: Implement the composable**

`src/tenant/modules/reports/composables/useBalanceSheet.ts`:

```ts
import { computed, onMounted, ref } from 'vue'
import { balanceSheetApi, type BalanceSheetResponse } from '@/tenant/apis/reports/balanceSheetApi'
import { allExpandableKeys, buildStatementRows, defaultExpandedKeys } from '../utils/balanceSheetRows'
import { percentChange } from '../utils/accountingFormat'

function todayIso(): string {
  const d = new Date()
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

export interface BalanceSheetKpi {
  key: 'assets' | 'liabilities' | 'equity'
  label: string
  amount: number
  compareAmount: number
  change: number | null
}

export function useBalanceSheet(options: { autoLoad?: boolean } = {}) {
  const asAt      = ref(todayIso())
  const compareTo = ref('')          // empty → server picks the prior financial year end
  const hideZero  = ref(true)
  const loading   = ref(false)
  const error     = ref<string | null>(null)
  const result    = ref<BalanceSheetResponse | null>(null)
  const expanded  = ref<Set<string>>(new Set())

  const rows       = computed(() => result.value ? buildStatementRows(result.value, expanded.value) : [])
  const totals     = computed(() => result.value?.totals ?? null)
  const isBalanced = computed(() => totals.value?.current.is_balanced === true)

  const kpis = computed<BalanceSheetKpi[]>(() => {
    if (!totals.value) return []
    const { current: c, compare: p } = totals.value
    const kpi = (key: BalanceSheetKpi['key'], label: string, amount: number, compareAmount: number): BalanceSheetKpi =>
      ({ key, label, amount, compareAmount, change: percentChange(amount, compareAmount) })
    return [
      kpi('assets', 'Total Assets', c.total_assets, p.total_assets),
      kpi('liabilities', 'Total Liabilities', c.total_liabilities, p.total_liabilities),
      kpi('equity', "Members' Funds", c.total_equity, p.total_equity),
    ]
  })

  /** Ledger drill-down window: financial year start (or 1 Jan) up to the as-at date. */
  const drillRange = computed(() => {
    if (!result.value) return null
    return {
      from: result.value.financial_year?.start_date ?? `${result.value.as_at.slice(0, 4)}-01-01`,
      to: result.value.as_at,
    }
  })

  async function generate() {
    loading.value = true
    error.value   = null
    try {
      const res = await balanceSheetApi.getBalanceSheet({
        as_at: asAt.value,
        compare_to: compareTo.value || undefined,
        hide_zero: hideZero.value ? 1 : 0,
      })
      result.value    = res
      compareTo.value = res.compare_to
      expanded.value  = defaultExpandedKeys(res)
    } catch (e: any) {
      result.value = null
      error.value  = e?.response?.data?.message ?? 'Failed to load balance sheet.'
    } finally {
      loading.value = false
    }
  }

  function toggle(key: string) {
    const next = new Set(expanded.value)
    if (next.has(key)) next.delete(key)
    else next.add(key)
    expanded.value = next
  }

  function expandAll()   { if (result.value) expanded.value = allExpandableKeys(result.value) }
  function collapseAll() { expanded.value = new Set() }

  if (options.autoLoad !== false) onMounted(generate)

  return {
    asAt, compareTo, hideZero, loading, error, result, expanded,
    rows, totals, isBalanced, kpis, drillRange,
    generate, toggle, expandAll, collapseAll,
  }
}
```

- [ ] **Step 12: Run all new tests**

Run: `pnpm vitest run src/tenant/modules/reports/__tests__`
Expected: PASS (10 tests).

- [ ] **Step 13: Commit**

```bash
git add src/tenant/apis/reports/balanceSheetApi.ts src/tenant/modules/reports/utils src/tenant/modules/reports/composables/useBalanceSheet.ts src/tenant/modules/reports/__tests__
git commit -m "feat(reports): add balance sheet data layer

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Extract the shared ledger drill-down drawer

**Files:**
- Create: `src/tenant/modules/reports/components/LedgerDrillDownDrawer.vue`
- Modify: `src/tenant/modules/reports/composables/useTrialBalance.ts`
- Modify: `src/tenant/modules/reports/pages/TrialBalance.vue`

**Interfaces:**
- Consumes: `trialBalanceApi.getLedgerLines({ account_id, from, to, page })`, which returns `{ data, total, last_page }`.
- Produces: `<LedgerDrillDownDrawer v-model:open="bool" :account="{ id: number; gl_code: string | null; name: string } | null" :from="iso" :to="iso" />`. The drawer fetches page 1 whenever it opens or its account or dates change.

- [ ] **Step 1: Create the drawer component**

`src/tenant/modules/reports/components/LedgerDrillDownDrawer.vue`:

```vue
<script setup lang="ts">
import { ref, watch } from 'vue'
import { X } from 'lucide-vue-next'
import { Spinner, formatMoneyValue } from '@/Global'
import { trialBalanceApi } from '@/tenant/apis/reports/trialBalanceApi'

export interface DrillAccount {
  id: number
  gl_code: string | null
  name: string
}

const props = defineProps<{ account: DrillAccount | null; from: string; to: string }>()
const open = defineModel<boolean>('open', { required: true })

const lines    = ref<any[]>([])
const page     = ref(1)
const total    = ref(0)
const lastPage = ref(1)
const loading  = ref(false)
const error    = ref<string | null>(null)

async function fetchPage() {
  if (!props.account) return
  loading.value = true
  error.value   = null
  try {
    const res = await trialBalanceApi.getLedgerLines({
      account_id: props.account.id, from: props.from, to: props.to, page: page.value,
    })
    lines.value    = page.value === 1 ? res.data : [...lines.value, ...res.data]
    total.value    = res.total
    lastPage.value = res.last_page
  } catch (e: any) {
    error.value = e?.response?.data?.message ?? 'Failed to load ledger lines.'
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  page.value++
  await fetchPage()
}

watch(
  () => [open.value, props.account?.id, props.from, props.to] as const,
  ([isOpen]) => {
    if (!isOpen || !props.account) return
    page.value  = 1
    lines.value = []
    fetchPage()
  },
  { immediate: true },
)
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex justify-end bg-neutral-950/20" @click.self="open = false">
      <div class="h-full w-full max-w-xl bg-white dark:bg-neutral-900 shadow-2xl flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-neutral-100 dark:border-neutral-800">
          <div>
            <p class="text-xs font-mono text-neutral-400">{{ account?.gl_code }}</p>
            <p class="font-bold text-neutral-900 dark:text-white">{{ account?.name }}</p>
            <p class="text-xs text-neutral-400 mt-0.5">{{ from }} → {{ to }}</p>
          </div>
          <button type="button" aria-label="Close" @click="open = false"
            class="p-2 rounded-full hover:bg-neutral-100 dark:hover:bg-neutral-800">
            <X class="w-4 h-4 text-neutral-500" />
          </button>
        </div>

        <div class="flex-1 overflow-y-auto">
          <div v-if="loading && page === 1" class="flex items-center justify-center py-12">
            <Spinner class="h-6 w-6 text-nfuko-primary" />
          </div>
          <p v-else-if="error" class="px-6 py-12 text-center text-sm text-rose-600 dark:text-rose-400">{{ error }}</p>
          <table v-else class="w-full text-xs">
            <thead class="sticky top-0 bg-neutral-50 dark:bg-neutral-800 font-bold uppercase tracking-wider text-neutral-400">
              <tr>
                <th class="px-4 py-3 text-left">Date</th>
                <th class="px-4 py-3 text-left">Reference</th>
                <th class="px-4 py-3 text-right">Debit</th>
                <th class="px-4 py-3 text-right">Credit</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
              <tr v-for="(line, i) in lines" :key="i" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40 transition">
                <td class="px-4 py-2.5 text-neutral-500">{{ line.date }}</td>
                <td class="px-4 py-2.5">
                  <div class="font-mono text-neutral-700 dark:text-neutral-300">{{ line.entry_no }}</div>
                  <div class="text-neutral-400 truncate max-w-[180px]">{{ line.description }}</div>
                </td>
                <td class="px-4 py-2.5 text-right text-blue-600 font-semibold">{{ line.debit ? formatMoneyValue(line.debit) : '—' }}</td>
                <td class="px-4 py-2.5 text-right text-orange-600 font-semibold">{{ line.credit ? formatMoneyValue(line.credit) : '—' }}</td>
              </tr>
              <tr v-if="!lines.length && !loading">
                <td colspan="4" class="px-4 py-12 text-center text-neutral-400">No transactions in this period.</td>
              </tr>
            </tbody>
          </table>

          <div v-if="page < lastPage" class="p-4 text-center">
            <button type="button" @click="loadMore" :disabled="loading"
              class="text-xs font-bold text-nfuko-primary hover:underline disabled:opacity-50">
              {{ loading ? 'Loading...' : `Load more — page ${page + 1} of ${lastPage}` }}
            </button>
          </div>
        </div>

        <div class="px-6 py-3 border-t border-neutral-100 dark:border-neutral-800 text-xs text-neutral-500">
          {{ total }} transaction{{ total !== 1 ? 's' : '' }}
        </div>
      </div>
    </div>
  </Teleport>
</template>
```

- [ ] **Step 2: Slim down `useTrialBalance.ts`**

In `src/tenant/modules/reports/composables/useTrialBalance.ts`:

1. Replace the drill-down state block:

```ts
  // Drill-down drawer
  const drawerOpen     = ref(false)
  const drawerAccount  = ref<any>(null)
  const drawerLines    = ref<any[]>([])
  const drawerPage     = ref(1)
  const drawerTotal    = ref(0)
  const drawerLastPage = ref(1)
  const drawerLoading  = ref(false)
  const drawerError    = ref<string | null>(null)
```

with:

```ts
  // Drill-down drawer (data loading lives in LedgerDrillDownDrawer)
  const drawerOpen    = ref(false)
  const drawerAccount = ref<any>(null)
```

2. Replace `openDrillDown`, `fetchDrillDown` and `loadMore` (everything from `async function openDrillDown` through the end of `async function loadMore() {...}`) with:

```ts
  function openDrillDown(account: any, side: 'debit' | 'credit') {
    if (!account.is_postable) return
    const amount = side === 'debit'
      ? (mode.value === 'period' ? account.period_debit  : account.closing_debit)
      : (mode.value === 'period' ? account.period_credit : account.closing_credit)
    if (!amount) return
    drawerAccount.value = account
    drawerOpen.value    = true
  }
```

3. Replace the two return lines:

```ts
    drawerOpen, drawerAccount, drawerLines, drawerPage, drawerTotal, drawerLastPage, drawerLoading, drawerError,
    generate, openDrillDown, loadMore,
```

with:

```ts
    drawerOpen, drawerAccount,
    generate, openDrillDown,
```

- [ ] **Step 3: Use the drawer in `TrialBalance.vue`**

1. Replace the script's imports and destructure:

```ts
import { Scale, AlertTriangle, CheckCircle, Download } from 'lucide-vue-next'
import { Spinner } from '@/Global'
import { useTrialBalance } from '../composables/useTrialBalance'
import LedgerDrillDownDrawer from '../components/LedgerDrillDownDrawer.vue'

const {
  mode, asOfDate, periodFrom, periodTo, hideZero, loading, result, exporting, error,
  accounts, totals, isBalanced, drFrom, drTo,
  drawerOpen, drawerAccount,
  generate, openDrillDown,
  exportCsv, exportExcel, exportPdf,
  fmt, fmtCell, typeColor,
} = useTrialBalance()
```

2. Replace the whole `<!-- Drill-down drawer -->` `<Teleport to="body">…</Teleport>` block with:

```vue
    <!-- Drill-down drawer -->
    <LedgerDrillDownDrawer v-model:open="drawerOpen" :account="drawerAccount" :from="drFrom" :to="drTo" />
```

- [ ] **Step 4: Type-check and regression check**

Run: `pnpm type-check`
Expected: no errors in `reports/`. If there are errors elsewhere, compare against `main`. Fix only errors this task introduced.

Manual check: `pnpm dev`, open `/tenant/reports/trial-balance` and click a non-zero Closing DR amount. The drawer should open with ledger lines, "Load more" should work, and clicking the backdrop should close it.

- [ ] **Step 5: Commit**

```bash
git add src/tenant/modules/reports/components/LedgerDrillDownDrawer.vue src/tenant/modules/reports/composables/useTrialBalance.ts src/tenant/modules/reports/pages/TrialBalance.vue
git commit -m "refactor(reports): extract ledger drill-down drawer

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Statement row and KPI components

**Files:**
- Create: `src/tenant/modules/reports/components/BalanceSheetRow.vue`
- Create: `src/tenant/modules/reports/components/BalanceSheetKpis.vue`
- Test: `src/tenant/modules/reports/__tests__/BalanceSheetRow.spec.ts`

**Interfaces:**
- Consumes: `StatementRow` (Task 3), `formatAccounting`, `formatShortDate`, and `BalanceSheetKpi` from `useBalanceSheet`.
- Produces:
  - `<BalanceSheetRow :row="StatementRow" @toggle="(key: string) => …" @drill="(line: BalanceSheetLine) => …" />`, whose root is a `<tr>`.
  - `<BalanceSheetKpis :kpis="BalanceSheetKpi[]" :is-balanced="boolean" :difference="number" :compare-to="iso" />`

- [ ] **Step 1: Write the failing test**

`src/tenant/modules/reports/__tests__/BalanceSheetRow.spec.ts`:

```ts
/* @vitest-environment jsdom */
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@/Global', () => ({
  formatMoneyValue: (v: number | string) =>
    Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
}))

import BalanceSheetRow from '../components/BalanceSheetRow.vue'
import type { StatementRow } from '../utils/balanceSheetRows'
import { line } from './fixtures/balanceSheet'

function row(partial: Partial<StatementRow>): StatementRow {
  return {
    key: 'k', kind: 'line', label: 'Row', glCode: null, depth: 1,
    amount: 0, compareAmount: 0, hasChildren: false, expanded: false, line: null, ...partial,
  }
}

function mountRow(r: StatementRow) {
  const table = document.createElement('tbody')
  document.body.appendChild(table)
  return mount(BalanceSheetRow, { props: { row: r }, attachTo: table })
}

describe('BalanceSheetRow', () => {
  it('renders negatives in parentheses and the change column', () => {
    const provision = line({ id: 4, gl_code: '11401', name: 'Loan Loss Provision', is_postable: true, amount: -4000 })
    const w = mountRow(row({ label: provision.name, glCode: '11401', amount: -4000, compareAmount: 0, line: provision }))

    const cells = w.findAll('td').map(td => td.text())
    expect(cells[1]).toContain('(4,000.00)')
    expect(cells[2]).toBe('—')
    expect(cells[3]).toContain('(4,000.00)')
  })

  it('emits drill only for postable, non-computed lines with a balance', async () => {
    const cash = line({ id: 3, gl_code: '11101', name: 'Cash at Bank', is_postable: true, amount: 89000 })
    const w = mountRow(row({ label: cash.name, amount: 89000, compareAmount: 80000, line: cash }))

    await w.get('[data-test="drill"]').trigger('click')
    expect(w.emitted('drill')?.[0]).toEqual([cash])

    const surplus = line({ name: 'Surplus / (Deficit) – Current Year', is_computed: true, amount: 5000 })
    const c = mountRow(row({ label: surplus.name, amount: 5000, line: surplus }))
    expect(c.find('[data-test="drill"]').exists()).toBe(false)
    expect(c.text()).toContain('computed')
  })

  it('emits toggle from the chevron with aria-expanded state', async () => {
    const group = line({ id: 2, gl_code: '11000', name: 'Current Assets', children: [line({ name: 'x' })] })
    const w = mountRow(row({ key: '2', label: group.name, hasChildren: true, expanded: false, line: group, amount: 1 }))

    const btn = w.get('[data-test="toggle"]')
    expect(btn.attributes('aria-expanded')).toBe('false')
    await btn.trigger('click')
    expect(w.emitted('toggle')?.[0]).toEqual(['2'])
  })
})
```

- [ ] **Step 2: Run to verify failure**

Run: `pnpm vitest run src/tenant/modules/reports/__tests__/BalanceSheetRow.spec.ts`
Expected: FAIL. `../components/BalanceSheetRow.vue` cannot be resolved.

- [ ] **Step 3: Implement `BalanceSheetRow.vue`**

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { ChevronRight } from 'lucide-vue-next'
import type { BalanceSheetLine } from '@/tenant/apis/reports/balanceSheetApi'
import type { StatementRow } from '../utils/balanceSheetRows'
import { formatAccounting } from '../utils/accountingFormat'

const props = defineProps<{ row: StatementRow }>()
const emit = defineEmits<{ toggle: [key: string]; drill: [line: BalanceSheetLine] }>()

const change = computed(() =>
  props.row.amount === null || props.row.compareAmount === null
    ? null
    : props.row.amount - props.row.compareAmount,
)

const canDrill = computed(() =>
  props.row.kind === 'line'
  && !!props.row.line?.is_postable
  && !props.row.line?.is_computed
  && !!props.row.amount,
)

const labelStyle = computed(() => ({ paddingLeft: `${1 + props.row.depth * 1.25}rem` }))

const changeClass = computed(() => {
  const v = Math.round((change.value ?? 0) * 100)
  if (v > 0) return 'text-emerald-600 dark:text-emerald-400'
  if (v < 0) return 'text-rose-600 dark:text-rose-400'
  return 'text-neutral-400 dark:text-neutral-500'
})

const isTotal = computed(() => ['subtotal', 'section-total', 'grand-total'].includes(props.row.kind))

const amountRule = computed(() => ({
  subtotal: 'border-t border-neutral-300 dark:border-neutral-600',
  'section-total': 'border-t border-neutral-400 dark:border-neutral-500',
  'grand-total': 'border-t border-neutral-900 border-b-[3px] border-b-neutral-900 border-double dark:border-neutral-200 dark:border-b-neutral-200',
  section: '',
  line: '',
}[props.row.kind]))

const COMPUTED_HINT = 'Calculated from income and expense accounts — no year-end closing has been posted.'
</script>

<template>
  <!-- Section heading -->
  <tr v-if="row.kind === 'section'">
    <td colspan="4" class="px-4 pt-7 pb-2 text-[11px] font-bold uppercase tracking-[0.16em] text-nfuko-primary">
      {{ row.label }}
    </td>
  </tr>

  <tr v-else
    :class="[
      'group transition-colors',
      row.kind === 'line' ? 'hover:bg-neutral-50 dark:hover:bg-neutral-800/40' : '',
      row.kind === 'grand-total' ? 'bg-nfuko-primary/5 dark:bg-nfuko-primary/10' : '',
    ]">
    <!-- Label -->
    <td :style="labelStyle"
      :class="[
        'py-2 pr-4 text-sm',
        isTotal ? 'font-semibold text-neutral-900 dark:text-white' : 'text-neutral-700 dark:text-neutral-300',
        row.kind === 'grand-total' ? 'py-3 font-bold uppercase tracking-wide text-[13px]' : '',
        row.kind === 'line' && row.depth === 1 ? 'font-medium text-neutral-900 dark:text-neutral-100' : '',
      ]">
      <div class="flex items-center gap-1.5">
        <button v-if="row.hasChildren" type="button" data-test="toggle"
          :aria-expanded="row.expanded ? 'true' : 'false'"
          :aria-label="`${row.expanded ? 'Collapse' : 'Expand'} ${row.label}`"
          class="-ml-6 flex h-5 w-5 items-center justify-center rounded text-neutral-400 hover:bg-neutral-200/70 hover:text-neutral-700 dark:hover:bg-neutral-700 dark:hover:text-neutral-200"
          @click="emit('toggle', row.key)">
          <ChevronRight :class="['h-3.5 w-3.5 transition-transform', row.expanded ? 'rotate-90' : '']" />
        </button>
        <span v-if="row.glCode && row.line?.is_postable" class="font-mono text-[11px] text-neutral-400">{{ row.glCode }}</span>
        <span>{{ row.label }}</span>
        <span v-if="row.line?.is_computed" :title="COMPUTED_HINT"
          class="rounded-full bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:bg-amber-900/30 dark:text-amber-300">
          computed
        </span>
      </div>
    </td>

    <!-- As-at amount -->
    <td class="px-4 py-2 text-right text-sm tabular-nums whitespace-nowrap">
      <span :class="['inline-block min-w-[7rem] py-0.5', amountRule, isTotal ? 'font-semibold' : '']">
        <button v-if="canDrill" type="button" data-test="drill"
          class="text-neutral-900 underline decoration-neutral-300 decoration-dotted underline-offset-4 hover:text-nfuko-primary hover:decoration-nfuko-primary dark:text-neutral-100 dark:decoration-neutral-600"
          @click="emit('drill', row.line!)">
          {{ formatAccounting(row.amount) }}
        </button>
        <span v-else class="text-neutral-900 dark:text-neutral-100">{{ formatAccounting(row.amount) }}</span>
      </span>
    </td>

    <!-- Comparison amount -->
    <td class="px-4 py-2 text-right text-sm tabular-nums whitespace-nowrap text-neutral-500 dark:text-neutral-400">
      <span :class="['inline-block min-w-[7rem] py-0.5', amountRule]">{{ formatAccounting(row.compareAmount) }}</span>
    </td>

    <!-- Change -->
    <td :class="['px-4 py-2 text-right text-xs tabular-nums whitespace-nowrap', changeClass]">
      {{ formatAccounting(change) }}
    </td>
  </tr>
</template>
```

- [ ] **Step 4: Run to verify pass**

Run: `pnpm vitest run src/tenant/modules/reports/__tests__/BalanceSheetRow.spec.ts`
Expected: PASS (3 tests).

- [ ] **Step 5: Implement `BalanceSheetKpis.vue`**

```vue
<script setup lang="ts">
import { CheckCircle2, AlertTriangle, TrendingUp, TrendingDown } from 'lucide-vue-next'
import type { BalanceSheetKpi } from '../composables/useBalanceSheet'
import { formatAccounting, formatShortDate } from '../utils/accountingFormat'

defineProps<{ kpis: BalanceSheetKpi[]; isBalanced: boolean; difference: number; compareTo: string }>()
</script>

<template>
  <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <div v-for="kpi in kpis" :key="kpi.key"
      class="rounded-2xl border border-neutral-100 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
      <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-neutral-400">{{ kpi.label }}</p>
      <p class="mt-2 text-xl font-bold tabular-nums text-neutral-900 dark:text-white">{{ formatAccounting(kpi.amount) }}</p>
      <p class="mt-1 flex items-center gap-1 text-xs text-neutral-500">
        <template v-if="kpi.change !== null">
          <TrendingUp v-if="kpi.change >= 0" class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" />
          <TrendingDown v-else class="h-3.5 w-3.5 text-rose-600 dark:text-rose-400" />
          <span :class="kpi.change >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'" class="font-semibold tabular-nums">
            {{ Math.abs(kpi.change).toFixed(1) }}%
          </span>
        </template>
        <span v-else class="font-semibold">—</span>
        <span>vs {{ formatShortDate(compareTo) }}</span>
      </p>
    </div>

    <div :class="['rounded-2xl border p-4 shadow-sm',
      isBalanced
        ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-900/20'
        : 'border-rose-200 bg-rose-50 dark:border-rose-800 dark:bg-rose-900/20']">
      <p class="text-[11px] font-bold uppercase tracking-[0.12em]"
        :class="isBalanced ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'">Balance check</p>
      <p class="mt-2 flex items-center gap-2 text-xl font-bold"
        :class="isBalanced ? 'text-emerald-800 dark:text-emerald-300' : 'text-rose-800 dark:text-rose-300'">
        <CheckCircle2 v-if="isBalanced" class="h-5 w-5" />
        <AlertTriangle v-else class="h-5 w-5" />
        {{ isBalanced ? 'Balanced' : `Out by ${formatAccounting(Math.abs(difference))}` }}
      </p>
      <p class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">Assets = Liabilities + Equity</p>
    </div>
  </div>
</template>
```

- [ ] **Step 6: Commit**

```bash
git add src/tenant/modules/reports/components/BalanceSheetRow.vue src/tenant/modules/reports/components/BalanceSheetKpis.vue src/tenant/modules/reports/__tests__/BalanceSheetRow.spec.ts
git commit -m "feat(reports): add balance sheet row and KPI components

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Exports (CSV, Excel, PDF)

**Files:**
- Create: `src/tenant/modules/reports/composables/useBalanceSheetExport.ts`

**Interfaces:**
- Consumes: `result: Ref<BalanceSheetResponse | null>`, `rows: Ref<StatementRow[]>` (from `useBalanceSheet`), `saccoBrandingState` from `@/tenant/apis/saccobranding/saccoBrandingApi`.
- Produces: `useBalanceSheetExport(result, rows)` returning `{ exporting, exportCsv, exportExcel, exportPdf }`.

The export code is mostly calls to xlsx and jspdf. Rather than unit tests, verify it by opening the exported files in Step 2.

- [ ] **Step 1: Implement the export composable**

```ts
import { ref, type Ref } from 'vue'
import * as XLSX from 'xlsx'
import jsPDF from 'jspdf'
import autoTable from 'jspdf-autotable'
import { saccoBrandingState } from '@/tenant/apis/saccobranding/saccoBrandingApi'
import type { BalanceSheetResponse } from '@/tenant/apis/reports/balanceSheetApi'
import type { StatementRow } from '../utils/balanceSheetRows'
import { formatAccounting, formatLongDate, formatShortDate } from '../utils/accountingFormat'

const round2 = (v: number) => Math.round(v * 100) / 100
const indent = (depth: number) => '  '.repeat(Math.max(depth - 1, 0))

export function useBalanceSheetExport(result: Ref<BalanceSheetResponse | null>, rows: Ref<StatementRow[]>) {
  const exporting = ref(false)

  function header(r: BalanceSheetResponse): string[] {
    return ['GL Code', 'Account', formatShortDate(r.as_at), formatShortDate(r.compare_to), 'Change']
  }

  /** Numeric cells stay numbers (or null) so spreadsheets can sum them. */
  function dataRows(): (string | number | null)[][] {
    return rows.value.map(row => [
      row.glCode ?? '',
      row.kind === 'section' ? row.label.toUpperCase() : indent(row.depth) + row.label,
      row.amount === null ? null : round2(row.amount),
      row.compareAmount === null ? null : round2(row.compareAmount),
      row.amount === null || row.compareAmount === null ? null : round2(row.amount - row.compareAmount),
    ])
  }

  function download(blob: Blob, filename: string) {
    const url  = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
  }

  function run(fn: (r: BalanceSheetResponse) => void) {
    if (!result.value) return
    exporting.value = true
    try { fn(result.value) } finally { exporting.value = false }
  }

  function exportCsv() {
    run(r => {
      const all = [header(r), ...dataRows()]
      const csv = all
        .map(cells => cells.map(c => `"${String(c ?? '').replace(/"/g, '""')}"`).join(','))
        .join('\n')
      download(new Blob([csv], { type: 'text/csv;charset=utf-8;' }), `balance-sheet-${r.as_at}.csv`)
    })
  }

  function exportExcel() {
    run(r => {
      const title = [[saccoBrandingState.sacco_name || 'SACCO'], ['Statement of Financial Position'], [`As at ${formatLongDate(r.as_at)}`], []]
      const ws = XLSX.utils.aoa_to_sheet([...title, header(r), ...dataRows()])
      const firstDataRow = title.length + 1
      for (let i = firstDataRow; i < firstDataRow + rows.value.length; i++) {
        for (const c of [2, 3, 4]) {
          const cell = ws[XLSX.utils.encode_cell({ r: i, c })]
          if (cell && typeof cell.v === 'number') cell.z = '#,##0.00;(#,##0.00);"-"'
        }
      }
      ws['!cols'] = [{ wch: 10 }, { wch: 48 }, { wch: 18 }, { wch: 18 }, { wch: 16 }]
      const wb = XLSX.utils.book_new()
      XLSX.utils.book_append_sheet(wb, ws, 'Balance Sheet')
      XLSX.writeFile(wb, `balance-sheet-${r.as_at}.xlsx`)
    })
  }

  function exportPdf() {
    run(r => {
      const doc   = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' })
      const pageW = doc.internal.pageSize.getWidth()
      const pageH = doc.internal.pageSize.getHeight()

      doc.setFont('helvetica', 'bold').setFontSize(14)
      doc.text(saccoBrandingState.sacco_name || 'SACCO', pageW / 2, 16, { align: 'center' })
      doc.setFontSize(11).text('Statement of Financial Position', pageW / 2, 23, { align: 'center' })
      doc.setFont('helvetica', 'normal').setFontSize(9).setTextColor(90)
      doc.text(`As at ${formatLongDate(r.as_at)}`, pageW / 2, 29, { align: 'center' })
      doc.setFontSize(7).text(`Generated ${new Date().toLocaleString()}`, pageW - 14, 35, { align: 'right' })
      doc.setTextColor(0)

      const kinds = rows.value.map(row => row.kind)
      autoTable(doc, {
        startY: 38,
        head: [['Account', formatShortDate(r.as_at), formatShortDate(r.compare_to), 'Change']],
        body: rows.value.map(row => [
          row.kind === 'section' ? row.label.toUpperCase() : indent(row.depth) + row.label,
          formatAccounting(row.amount),
          formatAccounting(row.compareAmount),
          row.amount === null || row.compareAmount === null ? '' : formatAccounting(row.amount - row.compareAmount),
        ]),
        theme: 'plain',
        headStyles: { fontStyle: 'bold', fontSize: 8, textColor: 60, lineWidth: { bottom: 0.3 }, lineColor: 60 },
        bodyStyles: { fontSize: 8, cellPadding: { top: 1.2, bottom: 1.2, left: 2, right: 2 } },
        columnStyles: { 0: { cellWidth: 92 }, 1: { halign: 'right' }, 2: { halign: 'right', textColor: 90 }, 3: { halign: 'right', textColor: 110 } },
        didParseCell: data => {
          if (data.section === 'head' && data.column.index > 0) data.cell.styles.halign = 'right'
          if (data.section !== 'body') return
          const kind = kinds[data.row.index]
          if (kind === 'section') {
            data.cell.styles.fontStyle = 'bold'
            data.cell.styles.textColor = [30, 100, 60]
          }
          if (kind === 'subtotal' || kind === 'section-total' || kind === 'grand-total') {
            data.cell.styles.fontStyle = 'bold'
          }
          if (kind === 'grand-total') {
            data.cell.styles.fillColor = [240, 245, 242]
            if (data.column.index > 0) {
              data.cell.styles.lineWidth = { top: 0.3, bottom: 0.6 }
              data.cell.styles.lineColor = 20
            }
          }
        },
      })

      let y = (doc as any).lastAutoTable.finalY + 22
      if (y > pageH - 20) { doc.addPage(); y = 40 }
      const colW = (pageW - 28) / 3
      doc.setFontSize(8).setTextColor(60)
      ;['Prepared by', 'Checked by', 'Approved by'].forEach((label, i) => {
        const x = 14 + i * colW
        doc.line(x, y, x + colW - 10, y)
        doc.text(label, x, y + 4)
        doc.text('Date: ____________', x, y + 9)
      })

      doc.save(`balance-sheet-${r.as_at}.pdf`)
    })
  }

  return { exporting, exportCsv, exportExcel, exportPdf }
}
```

If `pnpm type-check` rejects `lineWidth` objects or `setFont(...).setFontSize` chaining for the installed jspdf / jspdf-autotable versions, fall back to numeric `lineWidth: 0.4` and separate `doc.setFont(...)` / `doc.setFontSize(...)` calls.

- [ ] **Step 2: Verify after Task 7 wires the page** (the export buttons live on the page)

Leave the checkbox until Task 7 Step 4 runs, then confirm:
- The CSV opens in a spreadsheet with numbers in columns C–E and blank cells for section rows.
- The Excel file shows the title rows, and negatives display as `(4,000.00)`.
- The PDF is portrait A4 with the header, bold totals, a filled grand-total row, and the signature block.

- [ ] **Step 3: Commit**

```bash
git add src/tenant/modules/reports/composables/useBalanceSheetExport.ts
git commit -m "feat(reports): add balance sheet exports

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Balance Sheet page and route wiring

**Files:**
- Create: `src/tenant/modules/reports/pages/BalanceSheet.vue`
- Modify: `src/tenant/layouts/routes.ts:351-355`

**Interfaces:**
- Consumes: `useBalanceSheet`, `useBalanceSheetExport`, `BalanceSheetRow`, `BalanceSheetKpis`, `LedgerDrillDownDrawer`, `formatLongDate`, `formatShortDate`, `saccoBrandingState`, `Spinner`.
- Produces: the page at `/tenant/reports/balance-sheet`.

- [ ] **Step 1: Implement the page**

`src/tenant/modules/reports/pages/BalanceSheet.vue`:

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { Landmark, Download, AlertTriangle, ChevronsDownUp, ChevronsUpDown, RotateCw } from 'lucide-vue-next'
import { saccoBrandingState } from '@/tenant/apis/saccobranding/saccoBrandingApi'
import type { BalanceSheetLine } from '@/tenant/apis/reports/balanceSheetApi'
import { useBalanceSheet } from '../composables/useBalanceSheet'
import { useBalanceSheetExport } from '../composables/useBalanceSheetExport'
import { formatAccounting, formatLongDate, formatShortDate } from '../utils/accountingFormat'
import BalanceSheetRow from '../components/BalanceSheetRow.vue'
import BalanceSheetKpis from '../components/BalanceSheetKpis.vue'
import LedgerDrillDownDrawer, { type DrillAccount } from '../components/LedgerDrillDownDrawer.vue'

const {
  asAt, compareTo, hideZero, loading, error, result,
  rows, totals, isBalanced, kpis, drillRange,
  generate, toggle, expandAll, collapseAll,
} = useBalanceSheet()

const { exporting, exportCsv, exportExcel, exportPdf } = useBalanceSheetExport(result, rows)

const drawerOpen    = ref(false)
const drawerAccount = ref<DrillAccount | null>(null)

function openDrill(line: BalanceSheetLine) {
  if (line.id === null) return
  drawerAccount.value = { id: line.id, gl_code: line.gl_code, name: line.name }
  drawerOpen.value    = true
}

const inputClass = 'rounded-lg border border-neutral-200 bg-white py-2 px-3 text-sm outline-none focus:border-nfuko-primary focus:ring-2 focus:ring-nfuko-primary/15 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white'
const ghostBtn   = 'flex items-center gap-1.5 rounded-lg border border-neutral-200 bg-white px-3 py-1.5 text-xs font-semibold text-neutral-600 transition hover:bg-neutral-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'
</script>

<template>
  <div class="flex flex-col gap-6 p-4 sm:p-6">

    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-nfuko-primary/10">
          <Landmark class="h-5 w-5 text-nfuko-primary" />
        </div>
        <div>
          <h1 class="text-2xl font-bold text-neutral-900 dark:text-white">Balance Sheet</h1>
          <p class="text-sm text-neutral-500">Statement of Financial Position — what the SACCO owns, owes and holds for members.</p>
        </div>
      </div>

      <div v-if="result" class="flex items-center gap-2">
        <button type="button" :class="ghostBtn" :disabled="exporting" @click="exportCsv"><Download class="h-3.5 w-3.5" />CSV</button>
        <button type="button" :class="ghostBtn" :disabled="exporting" @click="exportExcel"><Download class="h-3.5 w-3.5" />Excel</button>
        <button type="button" :disabled="exporting" @click="exportPdf"
          class="flex items-center gap-1.5 rounded-lg bg-nfuko-primary px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-nfuko-primary/90 disabled:opacity-50">
          <Download class="h-3.5 w-3.5" />PDF
        </button>
      </div>
    </div>

    <!-- Controls -->
    <form class="flex flex-wrap items-end gap-4 rounded-2xl border border-neutral-100 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
      @submit.prevent="generate">
      <label class="flex flex-col gap-1">
        <span class="text-xs text-neutral-400">As at</span>
        <input v-model="asAt" type="date" required :class="inputClass" />
      </label>
      <label class="flex flex-col gap-1">
        <span class="text-xs text-neutral-400">Compare to</span>
        <input v-model="compareTo" type="date" :max="asAt" :class="inputClass" />
      </label>
      <button type="submit" :disabled="loading"
        class="rounded-full bg-nfuko-primary px-6 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-nfuko-primary/90 disabled:opacity-60">
        Generate
      </button>

      <div class="ml-auto flex flex-wrap items-center gap-4">
        <div v-if="result" class="flex items-center gap-1">
          <button type="button" :class="ghostBtn" @click="expandAll"><ChevronsUpDown class="h-3.5 w-3.5" />Expand all</button>
          <button type="button" :class="ghostBtn" @click="collapseAll"><ChevronsDownUp class="h-3.5 w-3.5" />Collapse all</button>
        </div>
        <label class="flex cursor-pointer select-none items-center gap-2">
          <button type="button" role="switch" :aria-checked="hideZero" @click="hideZero = !hideZero; generate()"
            :class="['relative h-5 w-9 rounded-full transition-colors', hideZero ? 'bg-nfuko-primary' : 'bg-neutral-300 dark:bg-neutral-600']">
            <span :class="['absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform', hideZero ? 'translate-x-4' : '']" />
          </button>
          <span class="text-xs font-medium text-neutral-500">Hide zero balances</span>
        </label>
      </div>
    </form>

    <!-- Error -->
    <div v-if="error && !loading"
      class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-800 dark:bg-rose-900/20">
      <p class="flex items-center gap-2 text-sm font-semibold text-rose-800 dark:text-rose-300">
        <AlertTriangle class="h-4 w-4" />{{ error }}
      </p>
      <button type="button" :class="ghostBtn" @click="generate"><RotateCw class="h-3.5 w-3.5" />Retry</button>
    </div>

    <!-- Loading skeleton -->
    <div v-if="loading" class="rounded-2xl border border-neutral-100 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900" aria-busy="true">
      <div class="mx-auto mb-8 h-4 w-64 animate-pulse rounded bg-neutral-100 dark:bg-neutral-800" />
      <div v-for="i in 10" :key="i" class="flex items-center justify-between py-2.5">
        <div class="h-3 animate-pulse rounded bg-neutral-100 dark:bg-neutral-800" :style="{ width: `${30 + (i * 7) % 35}%`, marginLeft: `${(i % 3) * 1.25}rem` }" />
        <div class="h-3 w-24 animate-pulse rounded bg-neutral-100 dark:bg-neutral-800" />
      </div>
    </div>

    <template v-if="result && totals && !loading">
      <BalanceSheetKpis :kpis="kpis" :is-balanced="isBalanced" :difference="totals.current.difference" :compare-to="result.compare_to" />

      <!-- Out-of-balance banner -->
      <div v-if="!isBalanced"
        class="flex flex-wrap items-center gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-800 dark:bg-rose-900/20">
        <AlertTriangle class="h-5 w-5 flex-shrink-0 text-rose-600" />
        <p class="text-sm font-semibold text-rose-800 dark:text-rose-300">
          Assets differ from Liabilities + Equity by {{ formatAccounting(Math.abs(totals.current.difference)) }}.
        </p>
        <RouterLink to="/tenant/reports/trial-balance" class="text-sm font-bold text-rose-700 underline underline-offset-4 dark:text-rose-300">
          Check the Trial Balance
        </RouterLink>
      </div>

      <!-- Statement -->
      <section class="overflow-hidden rounded-2xl border border-neutral-100 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
        <header class="border-b border-neutral-100 px-6 py-6 text-center dark:border-neutral-800">
          <p class="text-xs font-bold uppercase tracking-[0.2em] text-neutral-400">{{ saccoBrandingState.sacco_name || 'SACCO' }}</p>
          <h2 class="mt-1 text-lg font-bold text-neutral-900 dark:text-white">Statement of Financial Position</h2>
          <p class="text-sm text-neutral-500">As at {{ formatLongDate(result.as_at) }}</p>
        </header>

        <div class="overflow-x-auto px-2 pb-4">
          <table class="w-full min-w-[640px] border-collapse">
            <thead>
              <tr class="text-[11px] font-bold uppercase tracking-wider text-neutral-400">
                <th class="px-4 pt-4 pb-2 text-left font-bold">Account</th>
                <th class="px-4 pt-4 pb-2 text-right font-bold text-neutral-700 dark:text-neutral-200">{{ formatShortDate(result.as_at) }}</th>
                <th class="px-4 pt-4 pb-2 text-right font-bold">{{ formatShortDate(result.compare_to) }}</th>
                <th class="px-4 pt-4 pb-2 text-right font-bold">Change</th>
              </tr>
            </thead>
            <tbody>
              <BalanceSheetRow v-for="row in rows" :key="row.key" :row="row" @toggle="toggle" @drill="openDrill" />
            </tbody>
          </table>
        </div>

        <footer class="flex flex-wrap justify-between gap-2 border-t border-neutral-100 px-6 py-3 text-xs text-neutral-400 dark:border-neutral-800">
          <span>Amounts in brackets are negative (contra accounts). Click an account amount to see its ledger.</span>
          <span>Generated {{ new Date(result.generated_at).toLocaleString() }}</span>
        </footer>
      </section>
    </template>

    <LedgerDrillDownDrawer
      v-if="drillRange"
      v-model:open="drawerOpen"
      :account="drawerAccount"
      :from="drillRange.from"
      :to="drillRange.to"
    />
  </div>
</template>
```

- [ ] **Step 2: Wire the route**

In `src/tenant/layouts/routes.ts`, inside the `reports/balance-sheet` item (~line 351–355), replace:

```ts
            component: () => import('@/tenant/components/globals/ComingSoon.vue'),
```

with:

```ts
            component: () => import('@/tenant/modules/reports/pages/BalanceSheet.vue'),
```

Change only the balance-sheet item. The income-statement and cash-flow items stay on `ComingSoon.vue`.

- [ ] **Step 3: Static checks and unit tests**

Run: `pnpm type-check && pnpm lint && pnpm vitest run src/tenant/modules/reports`
Expected: no new type or lint errors in the touched files, and all reports tests pass.

- [ ] **Step 4: Manual verification in the browser**

Start backend (`composer dev` in the backend repo) and frontend (`pnpm dev`). Log in to a tenant with posted transactions and open `/tenant/reports/balance-sheet`:
- The page loads automatically. Compare-to fills with the prior financial year end, and the KPI cards and "Balanced" card appear.
- Total Assets matches Total Liabilities & Equity, and both match the Trial Balance (as-of mode) for the same date.
- Chevrons expand and collapse. Expand all / Collapse all work. Subtotal rows appear when a group is expanded.
- Contra accounts show in parentheses, and computed surplus lines show the "computed" pill and tooltip.
- Clicking a postable amount opens the ledger drawer. Computed lines are not clickable.
- Choosing a compare date after as-at is blocked by `max`. If sent anyway, the page shows the 422 message with Retry.
- Dark mode toggle: every surface, border and text colour adapts.
- At a ~400px viewport, the KPI cards stack and the statement scrolls horizontally inside its card.
- Run the three exports and complete Task 6 Step 2.

- [ ] **Step 5: Commit**

```bash
git add src/tenant/modules/reports/pages/BalanceSheet.vue src/tenant/layouts/routes.ts
git commit -m "feat(reports): add balance sheet page

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Self-review notes

- **Spec coverage:**
  - Endpoint, params and defaults: Tasks 1–2
  - Sign rule, contra accounts, generic tree and level-1 flattening: Task 1
  - Computed surplus split and placement: Task 1
  - Pruning and rounding: Tasks 1–2
  - Totals and `is_balanced`: Task 1
  - Types and API client: Task 3
  - Expandable rows, default expansion to level 2, Expand/Collapse all: Tasks 3 and 7
  - KPIs with % change: Tasks 3 and 5
  - Accounting visual rules: Task 5
  - Drill-down over the FY range: Tasks 3, 4 and 7
  - Shared drawer: Task 4
  - CSV, Excel and PDF with signature block: Task 6
  - Out-of-balance banner, skeleton, error with Retry, dark mode, mobile: Task 7
  - Route wiring: Task 7
  - Branch filtering and year-end closing are out of scope, and no task touches them.
- **Type consistency:** `lineKey` gives `String(id)` or `computed:<name>`. Task 3 tests use `'2'` and Task 7 toggles with `row.key`, so the formats match. `BalanceSheetKpi` is exported from `useBalanceSheet.ts` and used in `BalanceSheetKpis.vue`. `DrillAccount` is exported from the drawer's `<script setup>` and imported by the page. Vue SFCs can export types from `<script setup>`. If the linter objects, move `DrillAccount` into `balanceSheetApi.ts`.
