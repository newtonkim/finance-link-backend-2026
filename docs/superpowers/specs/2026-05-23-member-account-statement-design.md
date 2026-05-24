# Member Account Statement — Design

**Goal:** Replace the current member-profile **Statement** tab and its print output with a per-savings-account bank-style statement that matches the reference image, sources its totals from the server, and is accountant-correct.

**Approach:** New per-account backend endpoint computes the period's opening balance, period totals, closing balance, and per-transaction running balance server-side. The frontend renders the same DOM for both on-screen view and print (browser print dialog → "Save as PDF" handles the download case).

---

## 1. Routing

| Method | Path | Action |
|---|---|---|
| `GET` | `/api/v1/tenant/reports/savings-account-statement/{savings_account_id}` | `SavingsAccountStatementController::show` |

Query params (both optional):
- `date_from` — `YYYY-MM-DD`. Default: today − 90 days.
- `date_to` — `YYYY-MM-DD`. Default: today.

Auth: tenant Sanctum bearer + `X-Tenant-Subdomain`, same as every other tenant endpoint.

## 2. Backend components

- `app/Tenant/Http/Controllers/Api/V1/SavingsAccountStatementController.php` — thin controller. Validates the route id and the date params; injects the service; returns the result.
- `app/Tenant/Modules/Savings/Contracts/SavingsAccountStatementServiceInterface.php` — single method `buildStatement(int $savingsAccountId, ?string $dateFrom, ?string $dateTo): array`.
- `app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php` — implements the interface. All query/computation logic lives here.
- Binding: interface → concrete added to `app/Providers/AppServiceProvider.php` (matching the existing pattern for other Savings services).

## 3. Computation

Steps performed by the service in this order:

1. Load the savings account; 404 if not found or soft-deleted.
2. Load the owning member (`name`, `member_number`, `address`), branch (`name`), savings product (`name`, `type`).
3. **Opening balance** = sum over `transactions` where `savings_account_id = X`, `is_reversed = 0`, `deleted_at IS NULL`, `transaction_date < date_from` of `(credit_value − debit_value)`. See §4 for how each row resolves to a credit or debit value.
4. **Period transactions** = the same filter but `transaction_date BETWEEN date_from AND date_to`, ordered by `transaction_date ASC, id ASC`. Reversal rows (`is_reversed = 0` but with `reversal_of != null` — the reversal entry itself) are included like any other row.
5. For each period transaction, annotate with `credit`, `debit`, and `running_balance` (previous running balance + credit − debit, starting from the opening balance).
6. **Period totals:** `total_credit = Σ credit`, `total_debit = Σ debit`, `closing = opening + total_credit − total_debit`, `count = number of rows`.
7. **Reconciliation invariant** asserted before returning: `closing == running_balance of last row` (within a 0.01 tolerance). Mismatch raises a 500 — silent drift is unacceptable.

## 4. Credit / Debit mapping (the accounting contract)

The statement is from the **member's** perspective. A deposit increases their balance → **Credit** column. A withdrawal/charge decreases their balance → **Debit** column.

| `transactions.type` | Statement column | Value source |
|---|---|---|
| `deposit`, `transfer_in`, `interest`, opening-balance rows | **Credit** | `transactions.amount` |
| `withdrawal`, `withdraw`, `transfer_out` | **Debit** | `transactions.amount` |
| `charge`, `general-charge` | **Debit** | `transactions.amount` |
| `deposit-charge`, `withdraw-charge` | **Debit** | `transactions.charge_amount` (because `amount = 0` on these rows by current convention) |

Rules for ambiguous shapes:
- For a charge-type row where `amount > 0`, use `amount`; where `amount = 0` and `charge_amount > 0`, use `charge_amount`. Never sum the two.
- Unknown future `type` value → log a warning and skip the row (does not contribute to totals). Surfaced in the response under `warnings: [...]` so the UI can show "1 transaction was excluded" if non-zero.

## 5. Response shape

```jsonc
{
  "account":  {
    "id": 8,
    "account_no": "SA-00012",
    "account_type": "Voluntary",            // savings_accounts.account_type, title-cased
    "product_name": "General Savings"
  },
  "member":   {
    "id": 14,
    "name": "Maya Nyamu",
    "member_number": "MBRC-00bk-100",
    "address": "Kibada St, STE 108",
    "address_city": "Dar es salaam, TX 78521"
  },
  "branch":   { "name": "Main branch" },
  "period":   {
    "date_from": "2026-02-23",
    "date_to":   "2026-05-23",
    "statement_date": "2026-05-23"
  },
  "balances": {
    "opening":      175800.00,
    "total_credit": 510000.00,
    "total_debit":   94000.00,
    "closing":      591800.00,
    "count": 8
  },
  "transactions": [
    {
      "id": 19,
      "date": "2026-03-15",
      "description": "Savings Deposit — Initial deposit: 6000 blc :3994000",
      "credit":  3994000.00,
      "debit":         0.00,
      "running_balance": 4169800.00,
      "is_reversal": false
    }
  ],
  "warnings": []
}
```

Empty-cell rendering (Credit `0` when the row is a Debit, and vice versa) is a **frontend** concern — the API always returns both as numbers so the frontend can format consistently.

## 6. Frontend components

| File | Role |
|---|---|
| `src/tenant/modules/members/profile/statement.vue` (replaced) | The Statement tab — controls (account selector, date pickers, Print) + statement body |
| `src/tenant/modules/members/profile/composables/useAccountStatement.ts` (new) | Wraps the API call, loading/error state, re-fetch on filter change |

The current `MemberTransactionsTab.vue` is **not** used by the new Statement tab. It remains for the "Transactions" tab, untouched.

## 7. Layout (matches the reference image)

Outer container:
- A `no-print` toolbar at the top: account `<MultiSearchableSelect>`, From date input, To date input, **Print** button.
- A `#statement-print-area` element below it — the canonical statement body. This element is what `printElementId` prints.

Statement body — single page-style block (white background, padded):

- Top-right: `Page 1 of 1` (positioned absolutely, prints fine).
- Two-column grid header (`grid-cols-2`, `gap-8`):
  - **Left column (definition-list):**
    - Account Number — `{account.account_no}`
    - Statement Date — `{period.statement_date}` (formatted `MM/DD/YYYY`)
    - Period Covered — `{period.date_from}` to `{period.date_to}`
    - Member name (larger, semibold)
    - `{member.address}`
    - `{member.address_city}`
  - **Right column (definition-list, value right-aligned, monospace):**
    - Opening Balance — `{balances.opening}`
    - Total Credit Amount — `{balances.total_credit}`
    - Total Debit Amount — `{balances.total_debit}`
    - Closing Balance — `{balances.closing}`
    - Account Type — `{account.account_type}`
    - Number of Transactions — `{balances.count}`
- Below the header: `<{branch.name}>` (in chevrons, left-aligned, small text).

Table:
- Columns: `Date` (left), `Description` (left, flex-grow), `Credit` (right, monospace), `Debit` (right, monospace), `Balance` (right, monospace, semibold).
- Header row: gray background, uppercase, small, tracking-wide.
- Body rows: `even:bg-gray-50` shading; empty cells render as truly empty (no "0.00") for the column the row didn't use.
- Footer row spanning all columns: `--- End of Transactions ---` (when at least one transaction).
- For reversal rows: prepend `(Reversal) ` to the description; no special styling (banks show reversals as ordinary rows in their own date).

## 8. States

- **Loading** — skeleton rows in the table body; the header card shows skeleton bars for balance numbers. Account selector + date pickers remain interactive.
- **Empty** (no transactions in the period) — header still renders with `Number of Transactions: 0` and `Closing Balance = Opening Balance`. Table body shows a single centered cell: `No transactions in this period.` No "End of Transactions" footer.
- **Error** — inline red banner above the table with the error message and a **Retry** button. Header card shows dashes for balance numbers.

## 9. Print

- The whole DOM uses `@media print` rules (added to the component's `<style scoped>`).
- Hidden on print: anything with class `no-print`, the app shell (nav/sidebar — these are already hidden globally on print across the app).
- The statement body stays visible and stretches to page width.
- `print-color-adjust: exact` ensures the `even:bg-gray-50` row shading prints.
- Page-break rules: `break-inside: avoid` on each row, `break-after: avoid` on the header card.
- Click **Print** → `printElementId('statement-print-area')` → browser print dialog. "Save as PDF" in the dialog handles the download/share case — no server PDF needed.

## 10. Testing

Backend (Pest, in `tests/Tenant/Reports/`):
- `SavingsAccountStatementServiceTest.php`
  - Opening balance correctly excludes period rows.
  - Each credit/debit type maps to the right column with the right value source.
  - `deposit-charge` row with `amount=0` and `charge_amount=6000` produces a 6,000 debit, not zero.
  - Running balance reconciles: `closing == opening + Σcredit − Σdebit == running_balance of last row`.
  - Reversed originals are excluded (`is_reversed=true`); the reversal entry itself is included as a normal row.
  - Soft-deleted transactions are excluded.
  - Empty period returns `count=0` and `closing = opening`.
- `SavingsAccountStatementControllerTest.php`
  - 404 when the account id doesn't exist or is soft-deleted.
  - 422 on invalid date params.
  - Happy path returns the documented JSON shape.

Frontend (Vitest, in `src/tenant/modules/members/__tests__/`):
- `statement.spec.ts`
  - Renders the six header fields from a fixture.
  - Renders the transactions table; empty Credit column when the row is a Debit and vice versa.
  - Calls `printElementId('statement-print-area')` when Print is clicked.
  - Re-fetches when account or date changes.
  - Empty / loading / error states render correctly.

## 11. Out of scope

- Loans and Shares sections on the statement. The image is purely a savings-account statement; loans/shares stay in their existing dedicated views.
- Server-generated PDF (the browser-print "Save as PDF" path covers it).
- Posting historical transactions to the journal (covered separately by the `2026-05-20-unified-ledger-posting.md` plan).
- Multi-account "consolidated member ledger" view. Per-account only, per the approved Section 1 decision.

## 12. Reference

- Image spec: the bank-style statement provided by the user (Magabe Marwa, Account 562-238-557-197).
- Current files being replaced/touched:
  - `src/tenant/modules/members/profile/statement.vue` (replaced)
  - `routes/tenant_api.php` (one route added near line 86)
  - `app/Providers/AppServiceProvider.php` (one binding added)
- Related (not modified here): the existing `MemberStatementController` at `GET reports/member-statement/{memberId}` keeps doing the consolidated rollup — its broken `first_name`/`middle_name`/`last_name` references are a separate fix.
