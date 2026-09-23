# Balance Sheet (Statement of Financial Position) — Design

**Date:** 2026-09-23
**Status:** Approved (brainstorming), pending spec review
**Route:** `/tenant/reports/balance-sheet` (currently `ComingSoon.vue`)

## Goal

Replace the "Balance Sheet Coming Soon" placeholder with a standard, comparative
Statement of Financial Position for a SACCO tenant: Assets = Liabilities + Equity,
built from the general ledger, consistent with the Trial Balance report.

## Decisions

| Topic | Decision |
|---|---|
| Comparison | Comparative: `as_at` + `compare_to` columns, plus a Change column |
| Branch scope | Consolidated only. The top-bar branch selector does not filter this report |
| Detail level | Expandable: group lines by default, drill into GL accounts, "Expand all" toggle |
| Computation | Backend endpoint + service (Approach A). Frontend only renders |

## Context / constraints discovered

- The COA seeder (`SaccoCoaSeeder`) has a clean hierarchy via `parent_id` / `level`:
  level 1 sections (`10000` ASSETS, `20000` LIABILITIES, `30000` EQUITY), level 2
  subsections (Current / Non-Current), level 3 groups, level 4 postable accounts.
  Some postable accounts sit at level 2 or 3, so the tree must be built generically
  from `parent_id`, never by hardcoded GL codes or fixed depth.
- Contra accounts exist: `11400` Loan Loss Provisions and `12200` Accumulated
  Depreciation (under ASSET, normal balance CR), and `33300` Dividends Declared
  (under EQUITY, normal balance DR).
- **There is no year-end closing process.** INCOME/EXPENSE balances never move into
  retained earnings, so the balance sheet must compute a surplus line to balance.
- `general_ledger` has no `branch_id` (only `journal_entries` / `journal_entry_lines`
  do). This is why the report is consolidated only.
- `financial_years` (`FinancialYear` model: `start_date`, `end_date`) defines
  financial years. It may be empty for some tenants.
- Known COA oddity (out of scope, flagged to the user): `11200` Member Savings Control
  is typed ASSET/DR while `21100` Member Savings Liability also exists. The report
  shows accounts where the COA puts them. It does not reclassify them.

## Backend

### Endpoint

`GET /api/v1/tenant/reports/balance-sheet`, registered in `routes/tenant_api.php`
inside the existing `feature:reports` group next to the trial-balance routes.

Query params (validated in controller):

| Param | Rule | Default |
|---|---|---|
| `as_at` | `nullable\|date` | today |
| `compare_to` | `nullable\|date\|before_or_equal:as_at` | end of the previous financial year (see below) |
| `hide_zero` | `nullable\|boolean` | `true` |

**Default `compare_to`:** find the `financial_years` row containing `as_at`. The
default is the day before its `start_date`. If no row contains `as_at`, use 31 Dec
of the year before `as_at`.

### Service

- `App\Tenant\Modules\Accounting\Contracts\BalanceSheetServiceInterface`
  - `generate(Carbon $asAt, ?Carbon $compareTo, bool $hideZero = true): array`
- `App\Tenant\Modules\Accounting\Services\BalanceSheetService`, bound in
  `AppServiceProvider` like `TrialBalanceServiceInterface`.
- `App\Tenant\Http\Controllers\Api\V1\BalanceSheetController@index`

The service depends on `TrialBalanceServiceInterface`. It calls `asOfDate()` once per
date to get cumulative `closing_debit` / `closing_credit` per account. It reuses the
same aggregation, so the balance sheet always agrees with the Trial Balance.

### Computation rules

1. **Sign rule by section, not by account normal balance:**
   - ASSET lines: `amount = debit − credit`
   - LIABILITY / EQUITY lines: `amount = credit − debit`

   Contra accounts therefore come out negative and naturally reduce their group
   total. Example: Loan Loss Provisions shows as `(240,000)` under Assets.
2. **Tree:** build from `chart_of_accounts` `parent_id` for `account_type IN
   (ASSET, LIABILITY, EQUITY)`, ordered by `gl_code`. A non-postable line's amount is
   the sum of its children. A postable line with children adds its own balance to
   theirs.
3. **Computed surplus.** INCOME/EXPENSE are never closed, so for each date `D`:
   - `fyStart` = `start_date` of the financial year containing `D`. If there is none,
     use 1 Jan of `D`'s year.
   - `currentYearSurplus = Σ(income cr−dr) − Σ(expense dr−cr)` over `[fyStart, D]`
   - `priorUnclosedSurplus` = same formula over all dates `< fyStart`

   Both are added as lines under the Retained Earnings group (`33000`, found by
   `account_subtype = 'Retained Earnings'` and `level = 2`). If that group is missing,
   they go directly under the EQUITY section. They have `is_computed: true`, no `id`,
   and are labelled:
   - "Surplus / (Deficit) – Current Year"
   - "Retained Surplus – Prior Years (unclosed)"

   Income/expense sums use a single GL aggregate query per range, joined to
   `chart_of_accounts` on `account_type`.
4. **Zero pruning (`hide_zero=true`):** drop postable lines where both `amount` and
   `compare_amount` are 0, then drop non-computed headers left with no children and
   zero amounts. Computed lines are always shown.
5. **Rounding:** round to 2 dp at the resource layer, as `TrialBalanceRowResource` does.
6. **Totals** for each date:
   `total_assets`, `total_liabilities`, `total_equity`,
   `total_liabilities_and_equity`, `difference = assets − (liabilities + equity)`,
   `is_balanced = abs(difference) < 0.005`.

### Response shape

```json
{
  "as_at": "2026-09-23",
  "compare_to": "2025-12-31",
  "financial_year": { "name": "FY2026", "start_date": "2026-01-01", "end_date": "2026-12-31" },
  "generated_at": "2026-09-23T10:00:00+03:00",
  "sections": [
    {
      "key": "assets",
      "label": "Assets",
      "total": 12450000.00,
      "compare_total": 11500000.00,
      "lines": [
        {
          "id": 2, "gl_code": "11000", "name": "Current Assets", "level": 2,
          "amount": 10000000.00, "compare_amount": 9300000.00,
          "is_postable": false, "is_computed": false,
          "children": [ "…same shape…" ]
        }
      ]
    },
    { "key": "liabilities", "label": "Liabilities", "…": "…" },
    { "key": "equity", "label": "Equity / Members' Funds", "…": "…" }
  ],
  "totals": {
    "current": { "total_assets": 0, "total_liabilities": 0, "total_equity": 0,
                 "total_liabilities_and_equity": 0, "difference": 0, "is_balanced": true },
    "compare": { "…same keys…" }
  }
}
```

`financial_year` is `null` when no financial year contains `as_at`. When `compare_to`
is missing, it is resolved to the default and always returned. The comparison column
is never empty.

### Backend tests (Pest, `tests/Feature/Tenant/Reports/BalanceSheetTest.php`)

- Balances (`is_balanced = true`) after a mix of postings (deposit, loan disbursement,
  interest income, expense).
- Contra accounts (loan loss provision, accumulated depreciation) reduce total assets.
- Current-year surplus equals income − expense within the financial year. Postings
  before `fyStart` land in the prior-years line.
- Comparative column reflects balances at `compare_to` only.
- Default `compare_to` = day before financial year start, with a fallback to 31 Dec of
  the prior year when no financial year exists.
- `hide_zero` pruning keeps computed lines.
- Validation: invalid date → 422, `compare_to` after `as_at` → 422.

## Frontend (`finance-link-frontend-2026`)

### Wiring

`src/tenant/layouts/routes.ts`: point the `reports/balance-sheet` item's `component`
at `@/tenant/modules/reports/pages/BalanceSheet.vue` (replaces `ComingSoon.vue`).

### Files

| File | Responsibility |
|---|---|
| `src/tenant/apis/reports/balanceSheetApi.ts` | `getBalanceSheet(params)` + TS types (`BalanceSheetResponse`, `BalanceSheetLine`, `BalanceSheetSection`, `BalanceSheetTotals`) |
| `src/tenant/modules/reports/utils/accountingFormat.ts` | `formatAccounting` (parentheses for negatives, `—` for zero), `percentChange`, `formatLongDate`, `formatShortDate` |
| `src/tenant/modules/reports/utils/balanceSheetRows.ts` | Pure function that flattens the response tree into `StatementRow[]` (section / line / subtotal / section-total / grand-total) based on expansion. Screen and all exports use it |
| `src/tenant/modules/reports/composables/useBalanceSheet.ts` | `asAt`, `compareTo`, `hideZero`, `loading`, `error`, `result`; `expanded` set, `toggle(key)`, `expandAll()`, `collapseAll()`; `rows`; `generate()` on mount; KPI computeds (totals + % change) |
| `src/tenant/modules/reports/composables/useBalanceSheetExport.ts` | CSV / Excel (`xlsx`) / PDF (`jspdf` + `jspdf-autotable`) from `rows` |
| `src/tenant/modules/reports/components/BalanceSheetRow.vue` | Renders one `StatementRow`: indent by depth, chevron when expandable, amount / compare / change cells, `computed` badge, rules on totals, click postable amount → emit `drill` |
| `src/tenant/modules/reports/components/BalanceSheetKpis.vue` | KPI cards + balance check card |
| `src/tenant/modules/reports/components/LedgerDrillDownDrawer.vue` | Ledger drawer moved out of `TrialBalance.vue` (props: `open`, `account`, `from`, `to`; uses `trialBalanceApi.getLedgerLines`). `TrialBalance.vue` switches to it with identical behaviour |
| `src/tenant/modules/reports/pages/BalanceSheet.vue` | Page composition |

### Page layout

1. **Header:** icon tile (`Landmark`), "Balance Sheet", subtitle "Statement of
   Financial Position", and CSV / Excel / PDF buttons (same styles as Trial Balance)
   when data is loaded.
2. **Controls card:** "As at" date, "Compare to" date, Generate button,
   Expand all / Collapse all, and a "Hide zero balances" toggle.
3. **KPI row:** Total Assets, Total Liabilities, Members' Funds (Equity), each with %
   change vs the comparison date, plus a Balance check card (✓ Balanced / ✗ Out by X).
   The cards stack at narrow widths.
4. **Statement card (paper-like):** SACCO name (`saccoBrandingState.sacco_name`),
   "Statement of Financial Position", "As at {long date}". Column headers are the two
   formatted dates and "Change". Sections are ASSETS, then LIABILITIES, then EQUITY /
   MEMBERS' FUNDS. Totals are TOTAL ASSETS and TOTAL LIABILITIES & EQUITY.
5. **Out-of-balance banner** (rose) shows the difference and a link to Trial Balance
   when `is_balanced` is false.

### Visual rules

- Tabular numerals (`tabular-nums`), right-aligned amounts, negatives in parentheses
  `(240,000.00)`, zero shown as `—`.
- A single top rule on subtotals and a double bottom rule on the two grand totals.
- Change column is muted. Increase and decrease get subtle emerald / rose text only.
- Computed lines show a small "computed" pill with a tooltip: "Calculated from
  income and expense accounts — no year-end closing has been posted."
- Existing tokens: `nfuko-primary`, neutral palette, `dark:` variants throughout,
  `lucide-vue-next` icons, `Spinner` from `@/Global`, `formatMoneyValue`.
- The statement card is `overflow-x-auto` on small screens.
- Loading shows a skeleton of rows. Error shows a message and a Retry button. If there
  is no activity, the statement renders all zeros with computed lines.

### Interactions

- Group rows toggle expansion. "Expand all" expands every row that has children.
  Default: expanded to level 2 (subsections visible, level-3 groups collapsed).
- Clicking the `as_at` amount on a postable row opens `LedgerDrillDownDrawer` for
  `[financial_year.start_date ?? Jan 1 of as_at, as_at]`. Computed lines do not open it.
- Exports use the currently visible rows (respecting expansion). The file name is
  `balance-sheet-{as_at}.{ext}`. The PDF is portrait A4 with header (SACCO name, title,
  dates, generated timestamp), the statement table with bold section totals, and a
  footer signature block: "Prepared by ______  Checked by ______  Approved by ______".

### Frontend tests (Vitest)

- `accountingFormat`: parentheses, zero dash, % change with a zero base → `null`.
- `balanceSheetRows`: collapsed vs expanded output, subtotal rows, grand totals.
- `useBalanceSheet`: toggle / expandAll / collapseAll, default expansion, error state
  set on API failure.
- `BalanceSheetRow`: negative renders in parentheses, computed badge shown, drill
  emitted only for postable non-computed rows.

## Out of scope

- Branch-level balance sheets.
- A year-end closing process and journal.
- Income Statement and Cash Flow pages (still `ComingSoon`).
- Reclassifying the `11200` Member Savings Control COA oddity.
