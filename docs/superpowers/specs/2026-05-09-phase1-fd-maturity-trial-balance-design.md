# Phase 1: FD Maturity Journal Entries + Trial Balance — Design Spec

## Goal

Fix the two highest-impact accounting gaps in Mfuko Pro: (1) Fixed Deposit maturity actions currently post no journal entries, leaving liabilities permanently on the balance sheet after payout/rollover/conversion; (2) there is no Trial Balance report, making it impossible to verify that DR = CR across the system.

## Architecture

FD Maturity accounting follows the existing `ExpenseAccountingService` / `SavingsJournalService` pattern: a dedicated accounting service is injected into the existing maturity service and called inside the existing DB transaction. The Trial Balance is a new read-only reporting layer querying the existing `general_ledger` table — no new migrations required.

## Tech Stack

- **Backend**: Laravel 12, PHP 8.x, bcmath, GlPostingEngine, GeneralLedger model
- **Frontend**: Vue 3, TypeScript, Tailwind CSS, existing TableDrawer / PainPageHeader / Badge components
- **No new GL codes or migrations** — all FD JEs use existing 2111, 2112, 2113; TB reads existing `general_ledger` and `chart_of_accounts` tables

---

## Section 1: FD Maturity Journal Entries

### Background

`FixedDepositMaturityService::execute()` handles three maturity actions but only updates DB records. FD interest accrues into the FD balance as it posts (DR 5110 / CR 2113), so the FD balance at maturity = principal + all accumulated interest. That full balance must move in every maturity JE.

### Journal Entries

#### Rollover — close old FD, open new FD

```
DR: 2113 Fixed Deposits  [old account sub-ledger — liability reduced]
CR: 2113 Fixed Deposits  [new account sub-ledger — new FD opened]
Amount: closing balance of old FD (principal + accumulated interest)
JE Type: FD_ROLLOVER
```

#### Payout — release FD balance to member's savings account

```
DR: 2113 Fixed Deposits  [FD liability closed]
CR: 2111 Mandatory Savings Deposits  [if target is mandatory]
 OR 2112 Voluntary Savings Deposits  [if target is voluntary]
Amount: closing balance of old FD
JE Type: FD_PAYOUT
GL resolved: from target savings account type via SavingsCoaResolverInterface
```

#### Convert — reclassify FD into regular voluntary savings

```
DR: 2113 Fixed Deposits  [FD liability reclassified out]
CR: 2112 Voluntary Savings Deposits  [reclassified in]
Amount: closing balance of old FD
JE Type: FD_CONVERSION
Sub-ledger: same member, same account (type updated in place)
```

### Transactional Guarantee

All three JEs are posted inside the existing `DB::transaction` in `FixedDepositMaturityService::execute()`. If the JE fails, the status update and balance changes also roll back — no partial state.

### New Files

| File | Purpose |
|------|---------|
| `app/Tenant/Modules/Savings/Contracts/FdMaturityAccountingServiceInterface.php` | Interface declaring `postRollover(SavingsAccount $old, SavingsAccount $new): void`, `postPayout(SavingsAccount $fd, SavingsAccount $target): void`, `postConversion(SavingsAccount $fd): void` |
| `app/Tenant/Modules/Savings/Services/FdMaturityAccountingService.php` | Implements interface. Uses `GlPostingEngine` and `SavingsCoaResolverInterface`. Each method builds a `JournalEntry` with two `JournalEntryLine` records and calls `GlPostingEngine::post()` |

### Modified Files

| File | Change |
|------|--------|
| `FixedDepositMaturityService.php` | Inject `FdMaturityAccountingServiceInterface` via constructor. Call `postRollover()`, `postPayout()`, or `postConversion()` inside the existing transaction block for each action branch |
| `AppServiceProvider` (or Savings service provider) | Bind `FdMaturityAccountingServiceInterface::class => FdMaturityAccountingService::class` |

### Error Handling

- If the target savings account GL cannot be resolved (missing COA mapping), throw `AccountingException` — this rolls back the entire maturity transaction and returns a 422 to the caller with a clear message.
- Never silently skip the JE (existing pattern in `ShareAccountingService` of silent skip is not followed here — FD maturity is too material).

---

## Section 2: Trial Balance — Backend

### Endpoints

| Method | Route | Purpose |
|--------|-------|---------|
| `GET` | `/api/v1/tenant/reports/trial-balance` | Main TB — as-of-date or period |
| `GET` | `/api/v1/tenant/reports/trial-balance/ledger` | Drill-down: paginated JE lines for one account |

### Query Parameters

**Trial Balance endpoint:**

| Mode | Params | Behaviour |
|------|--------|-----------|
| As-of-date (default) | `?date=2026-05-31` | All GL movements from inception to date. Opening = 0, Closing = net cumulative |
| Period | `?from=2026-05-01&to=2026-05-31` | Opening = all movements before `from`; Period = movements within range; Closing = opening + period net |

If neither `date` nor `from/to` provided, defaults to as-of-date = today.

**Ledger drill-down:**
`?account_id=X&from=2026-05-01&to=2026-05-31&page=1`

### TB Row Response Shape

```json
{
  "gl_code": "1112",
  "name": "Cash at Bank – Operating Account",
  "account_type": "ASSET",
  "account_subtype": "Bank",
  "level": 4,
  "is_postable": true,
  "opening_debit": 5000000.00,
  "opening_credit": 0.00,
  "period_debit": 1500000.00,
  "period_credit": 800000.00,
  "closing_debit": 5700000.00,
  "closing_credit": 0.00
}
```

Non-postable header accounts are included in the response (for grouping in the frontend) but have null amounts.

For as-of-date mode: `opening_debit` and `opening_credit` are both `0`.

### TB Metadata (top-level response)

```json
{
  "mode": "period",
  "from": "2026-05-01",
  "to": "2026-05-31",
  "generated_at": "2026-05-09T14:32:00Z",
  "totals": {
    "total_opening_debit": 12500000.00,
    "total_opening_credit": 12500000.00,
    "total_period_debit": 4200000.00,
    "total_period_credit": 4200000.00,
    "total_closing_debit": 14800000.00,
    "total_closing_credit": 14800000.00,
    "is_balanced": true
  },
  "accounts": [ ... ]
}
```

`is_balanced` = `total_closing_debit === total_closing_credit` (bcmath comparison).

### Ledger Drill-Down Response Shape (per line)

```json
{
  "date": "2026-05-09",
  "entry_no": "SAV-20260509-014",
  "journal_type": "SAVINGS_DEPOSIT",
  "description": "Deposit — John Doe (ACC-001)",
  "debit": 500000.00,
  "credit": 0.00,
  "running_balance": 5700000.00,
  "entity_type": "SavingsAccount",
  "entity_id": 14
}
```

Paginated at 50 lines per page, ordered by `posted_at` ascending.

### GL Query Strategy

The `general_ledger` table tracks every posting with `debit_amount`, `credit_amount`, and `posted_at`. Two aggregation queries per TB request:

```sql
-- Opening (period mode only): all movements before $from
SELECT account_id, SUM(debit_amount) as dr, SUM(credit_amount) as cr
FROM general_ledger
WHERE posted_at < $from
GROUP BY account_id;

-- Period movements (or as-of-date: WHERE posted_at <= $date)
SELECT account_id, SUM(debit_amount) as dr, SUM(credit_amount) as cr
FROM general_ledger
WHERE posted_at BETWEEN $from AND $to
GROUP BY account_id;
```

Results are merged in PHP with the full `chart_of_accounts` list ordered by `gl_code`. Accounts with zero movement still appear (with 0.00) — correct accounting practice.

Running balance for drill-down is computed in PHP using a cumulative sum over the ordered result set, using bcmath for precision.

### New Files

| File | Purpose |
|------|---------|
| `app/Tenant/Modules/Accounting/Contracts/TrialBalanceServiceInterface.php` | Interface: `asOfDate(Carbon $date): array`, `forPeriod(Carbon $from, Carbon $to): array`, `ledgerLines(int $accountId, Carbon $from, Carbon $to, int $page): LengthAwarePaginator` |
| `app/Tenant/Modules/Accounting/Services/TrialBalanceService.php` | Implements interface. Two GL aggregate queries + merge with COA list. bcmath for all arithmetic |
| `app/Tenant/Http/Controllers/Api/V1/TrialBalanceController.php` | `index()` routes to `asOfDate` or `forPeriod` based on params. `ledger()` returns paginated drill-down. Both inject interface |
| `app/Tenant/Http/Resources/TrialBalanceRowResource.php` | Formats one GL account row including opening/period/closing DR/CR |
| `app/Tenant/Http/Resources/LedgerLineResource.php` | Formats one drill-down JE line including running balance |

### Modified Files

| File | Change |
|------|--------|
| `routes/tenant_api.php` | Add `GET reports/trial-balance` and `GET reports/trial-balance/ledger` inside the authenticated tenant middleware group |
| `AppServiceProvider` | Bind `TrialBalanceServiceInterface::class => TrialBalanceService::class` |

---

## Section 3: Trial Balance — Frontend

### Route

`/tenant/reports/trial-balance` — registered in the tenant reports route file.

### Page Structure

**Header area:**
- Title: "Trial Balance"
- Mode toggle: pill switcher — `As of Date` | `Period`
- Date input(s): single date picker (as-of-date) or from/to range (period)
- Generate button
- Export buttons: `Export PDF` | `Export CSV`

**Balance indicator banner (shown after generation):**

| State | Display |
|-------|---------|
| Balanced | Green — "✅ Books are balanced — Total DR UGX 14,800,000 = Total CR UGX 14,800,000" |
| Out of balance | Red — "⚠ Out of balance by UGX X — investigate unposted transactions" |
| No data | Neutral — "No transactions found for this period" |

**Table columns — As-of-Date mode:**

`GL Code` | `Account Name` | `Type` | `Debit` | `Credit`

**Table columns — Period mode:**

`GL Code` | `Account Name` | `Type` | `Opening DR` | `Opening CR` | `Period DR` | `Period CR` | `Closing DR` | `Closing CR`

**Table row behaviour:**
- Non-postable header accounts (level 1–2): bold section dividers, no amounts, not clickable
- Postable accounts with zero balance: shown in muted colour, not clickable
- Postable accounts with amounts: normal weight, **any non-zero DR or CR cell is clickable** → opens drill-down drawer

**Totals row:** pinned to bottom, bold, shows column sums. Balanced indicator repeats here.

### Drill-Down Drawer

Opens from the right when a DR or CR amount cell is clicked. Shows:

- Header: GL code + account name
- Sub-header: selected period
- Table: `Date` | `Reference` | `Description` | `Debit` | `Credit`
- Running balance shown on row hover
- Reference number is a tappable link — navigates to source transaction where entity_type is resolvable (SavingsAccount → savings transaction, Loan → loan detail, Expense → expense detail)
- Pagination: "Load more" at bottom (page-based, 50 per page)

### New Files

| File | Purpose |
|------|---------|
| `src/tenant/modules/reports/pages/TrialBalance.vue` | Main report page — mode toggle, date inputs, table, balance indicator, export |
| `src/tenant/apis/reports/trialBalanceApi.ts` | `getTrialBalance(params)` and `getLedgerLines(accountId, params)` — both use `tenantClient` |

### Modified Files

| File | Change |
|------|--------|
| Reports route file | Register `/trial-balance` route pointing to `TrialBalance.vue` |
| Reports sidebar / nav component | Add "Trial Balance" link with appropriate icon (e.g. `Scale` from lucide-vue-next) |

---

## Accounting Gap Coverage After Phase 1

| Gap | Status after Phase 1 |
|-----|----------------------|
| FD maturity — no JE | ✅ Fixed |
| No trial balance to verify DR=CR | ✅ Fixed |
| Dividends — no service | Phase 2 |
| Loan reschedule — no JE | Phase 3 |
| Reducing-balance accrual | Phase 4 |
| Share transfers, expense reversal | Phase 5 |

## Out of Scope for Phase 1

- Dividend declaration or payment
- Loan reschedule capitalization JEs
- Period-end interest accrual for reducing-balance loans
- Share transfer sub-ledger JEs
- Expense reversal JE
