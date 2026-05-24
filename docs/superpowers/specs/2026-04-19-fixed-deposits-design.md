# Fixed Deposits & Interest Design

> **Scope:** Fixed deposit lifecycle (open → active → matured → closed/rolled), interest calculation engine (at-maturity, periodic payout, compound), cron-free posting via on-access detection + manager month-end sweep, maturity actions, full double-entry accounting, and frontend hooks wired to the existing `type` dropdown in `SavingsProductForm.vue`.

---

## Goal

Deliver a fully-working fixed deposit product for SACCOs — member opens an FD, interest accrues, posts without any cron job, and the account matures into one of three configurable actions — all hooked into the existing savings product `type: fixed` dropdown already present in the UI.

## Architecture

**Approach: Event-Driven with Deferred Posting.** Two triggers replace cron jobs:
1. **On-access auto-detection** — when any user opens a FD account detail, the backend silently checks dates and posts any due interest before returning data.
2. **Manager month-end sweep** — one UI button triggers a bulk sweep of all active FDs whose interest or maturity date is past due.

Interest is calculated by a single `FixedDepositInterestService`. All postings are idempotent — the on-access trigger and the sweep can both fire for the same account without double-posting.

**Tech Stack:** Laravel 12 (backend), Vue 3 + TypeScript (frontend), MySQL (tenant DB), existing `LoanAccountingService` pattern for double-entry JEs.

---

## Existing Hook Points

- `savings_products.type` — already `enum('fixed', 'standard')` in the DB and in the UI dropdown at `tenant/settings/savings-products/create`
- `savings_accounts.interest_rate` — already on the account row
- `savings_accounts.account_type` — already has `'fixed'` as a valid value
- `SavingsJournalService` — already maps `account_type` containing `'fixed'` to GL `2113`
- `SavingsProductForm.vue` — `form.type` is bound to the dropdown; the FD settings section conditionally renders on `form.type === 'fixed'`

---

## Section 1: Data Model

### `savings_products` — new columns

| Column | Type | Default | Purpose |
|---|---|---|---|
| `interest_rate` | `decimal(5,4)` | `0.0000` | Annual interest rate, e.g. `0.1200` = 12% p.a. |
| `interest_payout_type` | `enum('at_maturity','periodic_payout','compound')` | `'at_maturity'` | How interest is delivered to the member |
| `interest_posting_frequency` | `enum('monthly','quarterly','semi_annually','annually')` | `'monthly'` | Posting cadence — used for `periodic_payout` and `compound` only |
| `default_tenor_months` | `int unsigned` | `6` | Default lock-in term shown when opening an FD |
| `maturity_action` | `enum('auto_rollover','manual','convert_to_savings')` | `'manual'` | What happens automatically at maturity |
| `convert_to_product_id` | `unsignedBigInt` nullable FK → `savings_products` | `null` | Target product for `convert_to_savings` action |
| `interest_expense_account_id` | `unsignedBigInt` nullable FK → `chart_of_accounts` | `null` | DR side of interest JE (SACCO cost) |
| `interest_payable_account_id` | `unsignedBigInt` nullable FK → `chart_of_accounts` | `null` | CR side for `compound` and `at_maturity` types |

> `interest_posting_frequency` is only enforced for `periodic_payout` and `compound` payout types. For `at_maturity`, this column is stored but ignored.

### `savings_accounts` — new columns

| Column | Type | Default | Purpose |
|---|---|---|---|
| `tenor_months` | `int unsigned` nullable | `null` | Agreed lock-in term for this specific account |
| `maturity_date` | `date` nullable | `null` | Computed at open: `opened_at + tenor_months` |
| `next_interest_date` | `date` nullable | `null` | Next periodic posting date; `null` for `at_maturity` accounts |
| `maturity_action` | `enum('auto_rollover','manual','convert_to_savings')` nullable | `null` | Per-account override; inherits from product if null |
| `payout_savings_account_id` | `unsignedBigInt` nullable FK → `savings_accounts` | `null` | For `periodic_payout`: which regular savings account receives interest |
| `last_interest_posted_at` | `timestamp` nullable | `null` | Idempotency guard — updated on every successful posting |

### New table: `savings_interest_postings`

Audit log — one row per interest posting event. Never deleted.

```sql
CREATE TABLE savings_interest_postings (
    id                  BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    savings_account_id  BIGINT UNSIGNED NOT NULL,
    period_start        DATE NOT NULL,
    period_end          DATE NOT NULL,
    principal           DECIMAL(15,2) NOT NULL,
    rate                DECIMAL(5,4) NOT NULL,
    interest_amount     DECIMAL(15,2) NOT NULL,
    payout_type         ENUM('at_maturity','periodic_payout','compound') NOT NULL,
    journal_entry_id    BIGINT UNSIGNED NULL,
    posted_by           INT NULL,          -- null = system auto-post
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_posting_period (savings_account_id, period_start, period_end),
    FOREIGN KEY (savings_account_id) REFERENCES savings_accounts(id)
);
```

The `UNIQUE KEY` on `(savings_account_id, period_start, period_end)` is the database-level idempotency guard.

---

## Section 2: Interest Calculation Engine

### Service: `FixedDepositInterestService`

Single responsibility: calculate and post interest for one FD account. Stateless — called by both triggers.

#### Formula

**Types A (at_maturity) and B (periodic_payout) — simple interest:**
```
interest = principal × annual_rate × (days_in_period / 365)
```

**Type C (compound) — compound on running balance:**
```
interest = current_balance × annual_rate × (days_in_period / 365)
```
The result is added to `savings_account.balance`. Next period compounds on the grown balance.

`days_in_period` = `period_end - period_start` in calendar days, always known from stored dates.

#### Main method: `processAccount(SavingsAccount $account, int $actorId): void`

```
1. Guard: account_type = 'fixed' AND status = 'active' — else return
2. Lock the account row with lockForUpdate()
3. Resolve effective maturity_action (account override → product default)

4. Check maturity: maturity_date <= today
   YES:
     a. Calculate interest from (last_interest_posted_at ?? created_at) → maturity_date (covers any gap between last periodic posting and maturity, regardless of payout type)
     b. Post interest JE (see Section 5)
     c. Insert row into savings_interest_postings
     d. Update last_interest_posted_at = now()
     e. Execute maturity action (see Section 4)
     f. Return

5. Check periodic due: next_interest_date <= today AND payout_type IN (periodic_payout, compound)
   YES:
     a. period_start = last_interest_posted_at ?? savings_accounts.created_at (first run fallback)
     b. period_end = next_interest_date
     c. Calculate interest
     d. Post interest JE
     e. Insert row into savings_interest_postings
     f. Update last_interest_posted_at = now()
     g. Advance next_interest_date by one frequency period
     h. Return

6. Nothing due — return silently
```

#### Sweep method: `runMonthEndSweep(int $actorId): array`

```php
SavingsAccount::where('account_type', 'fixed')
    ->where('status', 'active')
    ->where(fn($q) =>
        $q->whereDate('next_interest_date', '<=', today())
          ->orWhereDate('maturity_date', '<=', today())
    )
    ->each(fn($account) => $this->processAccount($account, $actorId));
```

Returns: `['posted' => N, 'skipped' => N, 'matured' => N, 'errors' => [...]]`

Errors list account numbers only — never throws, so one bad account does not abort the sweep.

---

## Section 3: The Two Triggers

### Trigger 1 — On-Access (SavingsAccountController::show)

`SavingsAccountController::show(int $id)` calls `FixedDepositInterestService::processAccount()` **before** loading and returning the account data. The member or officer always sees an up-to-date balance.

The `lockForUpdate()` inside `processAccount()` ensures concurrent requests for the same account produce exactly one posting.

### Trigger 2 — Manager Month-End Sweep

**Endpoint:** `POST /api/v1/tenant/savings/fixed-deposits/post-interest`

**Authorization:** `staff` or `admin` role only.

**Response:**
```json
{
  "message": "Interest posting complete.",
  "data": {
    "posted": 47,
    "skipped": 3,
    "matured": 5,
    "errors": [
      { "account_no": "FD-00123", "reason": "No interest expense account configured on product." }
    ]
  }
}
```

`skipped` = already posted this period (idempotency guard hit).
`errors` = accounts where GL accounts are missing on the product — listed so the manager can fix the product settings.

### Idempotency guarantee

| Guard | Level |
|---|---|
| `last_interest_posted_at` check | Application — fast early exit |
| `UNIQUE KEY (savings_account_id, period_start, period_end)` | Database — prevents duplicate rows even under race conditions |
| `lockForUpdate()` on account row | Database — serialises concurrent on-access calls |

---

## Section 4: Maturity Actions

All three actions execute inside the same DB transaction as the maturity interest posting.

### A — Auto-Rollover

1. Set old account `status = 'matured'`, record `matured_at = now()`
2. Create new `SavingsAccount` row:
   - Same `member_id`, `savings_product_id`, `branch_id`
   - `balance` = old account balance (already includes maturity interest just posted)
   - `tenor_months` = same tenor
   - `interest_rate` = **current product rate** (not original — rate may have changed)
   - `maturity_date` = today + tenor_months
   - `next_interest_date` computed from posting frequency (null for `at_maturity` type)
   - `maturity_action` inherited from product
3. Log activity: "FD auto-rolled over → new account `{new_account_no}`"

### B — Manual (status: matured)

1. Set `status = 'matured'` on the account
2. Balance retains the maturity interest just posted
3. No funds move automatically
4. UI shows a **"Process Maturity"** button (visible only when `status = 'matured'`)
5. Officer chooses:
   - **Withdraw** — full balance transferred to member's regular savings account or paid via payment method; account `status = 'closed'`
   - **Rollover** — executes the same steps as Action A

### C — Convert to Savings

1. Set `account_type = 'voluntary'` (or the product's standard type)
2. Set `savings_product_id = convert_to_product_id` from the FD product
3. Clear `maturity_date`, `next_interest_date`, `tenor_months` to `null`
4. `status` stays `'active'`
5. Balance retains maturity interest just posted
6. Log activity: "FD converted to regular savings account"

> Requires `convert_to_product_id` to be configured on the FD product. If missing, falls back to Action B (manual) and adds a warning to the activity log.

### Status lifecycle

```
opened_at                          maturity_date
    │                                   │
    ▼                                   ▼
 [active] ─────────────────────────► [matured] ──► [closed]    (manual withdraw)
                                         │
                                         ├──────────► [active, new FD]  (auto_rollover)
                                         │
                                         └──────────► [active, standard] (convert_to_savings)
```

---

## Section 5: Accounting Entries

JE type code: `SAV_INT`. Format: `JE-SAV_INT-YYYYMMDD-00001`.

Uses the existing `LoanAccountingService::postJournalEntry()` engine unchanged.

### Entry 1 — Periodic Payout (Type B)

Interest earned is credited to the member's linked regular savings account.

| Line | Account | DR | CR |
|---|---|---|---|
| 1 | Interest Expense on Savings (`interest_expense_account_id`) | interest_amount | |
| 2 | Member Regular Savings Liability (resolved from `payout_savings_account_id`'s product) | | interest_amount |

`savings_accounts.balance` on the payout account increases. FD principal unchanged.

### Entry 2 — Compound (Type C)

Interest stays inside the FD, growing the balance.

| Line | Account | DR | CR |
|---|---|---|---|
| 1 | Interest Expense on Savings (`interest_expense_account_id`) | interest_amount | |
| 2 | Fixed Deposit Liability (`interest_payable_account_id` or GL `2113`) | | interest_amount |

`savings_accounts.balance` on the FD account increases by `interest_amount`.

### Entry 3 — At Maturity (Type A)

Full interest for the entire tenor posted in one shot.

| Line | Account | DR | CR |
|---|---|---|---|
| 1 | Interest Expense on Savings | interest_amount | |
| 2 | Fixed Deposit Liability | | interest_amount |

Then the maturity action executes. If **withdrawn**, a second JE:

| Line | Account | DR | CR |
|---|---|---|---|
| 1 | Fixed Deposit Liability (`2113`) | full_balance | |
| 2 | Cash / Bank / Member Savings | | full_balance |

If **rolled over** or **converted** — no movement JE (same balance stays in system, just under a different account row).

### GL accounts required on `savings_products`

| Field | Typical GL | Normal Balance | Direction |
|---|---|---|---|
| `interest_expense_account_id` | `6201` Interest Expense on Deposits | DR | DR when posting |
| `interest_payable_account_id` | `2113` Fixed Deposit Liability | CR | CR when posting |

If either account is missing, the posting is skipped and logged as an error in the sweep result.

---

## Section 6: API Endpoints

### New endpoints

| Method | Path | Controller | Purpose |
|---|---|---|---|
| `GET` | `/savings/fixed-deposits` | `FixedDepositController@index` | List all FDs with maturity/interest dates |
| `POST` | `/savings/fixed-deposits/post-interest` | `FixedDepositController@postInterest` | Manager month-end sweep |
| `POST` | `/savings/accounts/{id}/maturity/process` | `FixedDepositController@processMaturity` | Officer: withdraw or rollover a matured FD |
| `GET` | `/savings/accounts/{id}/interest-postings` | `FixedDepositController@interestPostings` | Posting history for one FD |

### Extended existing endpoints

| Method | Path | Change |
|---|---|---|
| `GET` | `/savings/accounts/{id}` | Calls `processAccount()` before returning — on-access trigger |
| `POST` | `/savings/products` | Accepts and validates new FD fields |
| `PUT` | `/savings/products/{id}` | Same |
| `POST` | `/savings/accounts` | When `account_type = 'fixed'`, computes `maturity_date` and `next_interest_date` |

### `processMaturity` request body

```json
{
  "action": "withdraw | rollover",
  "payment_method": "cash | bank_transfer | savings",   // only for withdraw
  "payout_savings_account_id": 12                        // only for withdraw via savings
}
```

---

## Section 7: Frontend

### Hook point: `SavingsProductForm.vue`

The `form.type` dropdown already exists at line 234. A new **"Fixed Deposit Settings"** card renders immediately below the General Information section when `form.type === 'fixed'`.

**Fields in the FD Settings card:**

| Field | Control | Condition |
|---|---|---|
| Annual Interest Rate (%) | number input | always |
| Interest Payout Type | radio cards: At Maturity / Periodic Payout / Compound | always |
| Posting Frequency | select: Monthly / Quarterly / Semi-annually / Annually | only for Periodic Payout and Compound |
| Default Tenor (Months) | number input | always |
| Maturity Action | radio cards: Auto-Rollover / Manual / Convert to Savings | always |
| Convert To Product | savings product dropdown | only when Maturity Action = Convert to Savings |
| Interest Expense GL Account | COA search/select | always |
| Interest Payable GL Account | COA search/select | always |

**`SavingsProduct` TypeScript interface** extended:

```typescript
// New optional fields on SavingsProduct interface in savingsProducts/api.ts
interest_rate?: number | null
interest_payout_type?: 'at_maturity' | 'periodic_payout' | 'compound' | null
interest_posting_frequency?: 'monthly' | 'quarterly' | 'semi_annually' | 'annually' | null
default_tenor_months?: number | null
maturity_action?: 'auto_rollover' | 'manual' | 'convert_to_savings' | null
convert_to_product_id?: number | null
interest_expense_account_id?: number | null
interest_payable_account_id?: number | null
```

### Open Savings Account form — FD fields

When the selected product has `type = 'fixed'`, show additional fields:

| Field | Control | Default |
|---|---|---|
| Tenor (Months) | number input | `product.default_tenor_months` |
| Maturity Date | read-only date | computed: today + tenor |
| Payout Savings Account | member's savings account dropdown | member's primary standard account |
| Maturity Action override | optional select | inherits from product |

### FD Account Detail page

Extend the existing savings account detail with:
- Maturity date badge + `"Matures in N days"` or `"Matured N days ago"` pill
- Accrued-to-date interest display (calculated client-side for display, not posted)
- Interest Posting History table sourced from `/savings/accounts/{id}/interest-postings`
- **"Process Maturity"** button — visible only when `status = 'matured'`, opens `FixedDepositMaturityDrawer.vue`

### Fixed Deposits dashboard

New page: `tenant/savings/fixed-deposits`

- Table: Account No, Member, Balance, Rate, Payout Type, Maturity Date, Status
- Status badges: Active / Maturing Soon (≤30 days) / Matured / Closed
- Filters: by product, by maturity month, by status
- **"Post Monthly Interest"** button → calls sweep endpoint → shows result toast

### New frontend files

| File | Purpose |
|---|---|
| `src/tenant/modules/savings/pages/FixedDepositsDashboard.vue` | FD list + sweep button |
| `src/tenant/modules/savings/components/FixedDepositMaturityDrawer.vue` | Withdraw / rollover drawer |
| `src/tenant/modules/savings/components/InterestPostingHistory.vue` | Posting history table |

### Modified frontend files

| File | Change |
|---|---|
| `src/tenant/modules/settings/pages/SavingsProductForm.vue` | Add FD Settings card (conditional on `form.type === 'fixed'`) |
| `src/tenant/apis/savingsProducts/api.ts` | Extend `SavingsProduct` interface |
| `src/tenant/modules/savings/pages/SavingsAccountDetail.vue` | Maturity badge, interest history, process maturity button |
| `src/tenant/modules/savings/pages/SavingsAccountForm.vue` | FD-specific fields on account open |

---

## Section 8: New Backend Files

| File | Purpose |
|---|---|
| `app/Tenant/Modules/Savings/Services/FixedDepositInterestService.php` | Calculation + posting engine |
| `app/Tenant/Http/Controllers/Api/V1/FixedDepositController.php` | FD list, sweep, process maturity, posting history |
| `app/Tenant/Http/Requests/Tenant/ProcessMaturityRequest.php` | Validation for maturity action |
| `database/migrations/tenant/2026_04_19_000001_add_fixed_deposit_fields_to_savings_products.php` | New columns on savings_products |
| `database/migrations/tenant/2026_04_19_000002_add_fixed_deposit_fields_to_savings_accounts.php` | New columns on savings_accounts |
| `database/migrations/tenant/2026_04_19_000003_create_savings_interest_postings_table.php` | Audit log table |

### Modified backend files

| File | Change |
|---|---|
| `app/Tenant/Modules/Savings/Models/SavingsProduct.php` | Add new fillable + casts |
| `app/Tenant/Modules/Savings/Models/SavingsAccount.php` | Add new fillable + casts |
| `app/Tenant/Modules/Savings/Services/SavingsAccountService.php` | Compute maturity_date + next_interest_date on create |
| `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php` | Call processAccount() in show() |
| `app/Tenant/Modules/Settings/Services/SavingsProductService.php` | Accept + validate new FD fields |
| `routes/tenant_api.php` | Register new FD endpoints |

---

## Design Decisions

| Decision | Rationale |
|---|---|
| No cron jobs | Explicit user requirement. On-access + sweep provide equivalent coverage. |
| Idempotency at DB level | `UNIQUE KEY (account_id, period_start, period_end)` means no application-level dedup needed across concurrent callers. |
| `lockForUpdate()` on account | Prevents two simultaneous on-access calls posting twice during the same period window. |
| Simple interest for A/B | Standard SACCO practice. Avoids compounding complexity where not needed. |
| Compound uses running balance | Each period compounds on previous periods' interest, which is already in `savings_account.balance`. |
| Maturity action falls back to manual | If `convert_to_savings` is configured but `convert_to_product_id` is missing, fail safe to manual rather than throwing. |
| Sweep errors are non-fatal | One misconfigured product must not block all other accounts. Errors are collected and returned for the manager to fix. |
| Interest only on fixed products | Standard savings accounts earn no interest per user requirement (A). |
