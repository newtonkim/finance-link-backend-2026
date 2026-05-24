# Design: Chart of Accounts GL Code Expansion (4-digit → 5-digit)

**Date:** 2026-05-12
**Scope:** Backend (mfuko-pro-backend-2026) — seeder, tenant migration, constants class, service updates
**Status:** Approved

---

## Problem

The current 4-digit GL code scheme spaces Level 3 control accounts 10 units apart (e.g. `1110` Cash & Cash Equivalents, `1120` Member Savings Control). This gives each group a maximum of 9 child ledger slots. A SACCO with many bank accounts, mobile money channels, or loan products will exhaust a group and collide with the next control account's code.

---

## Decision

Move to **5-digit GL codes** using a consistent hierarchy pattern:

| Level | Pattern | Example |
|-------|---------|---------|
| 1 — Top header | `x0000` | `10000` ASSETS |
| 2 — Group | `xx000` | `11000` Current Assets |
| 3 — Control / sub-group | `xxx00` | `11100` Cash & Cash Equivalents |
| 4 — Postable leaf | `xxx01–xxx99` | `11101` Petty Cash |

Every Level 3 group gets **99 child slots**. This matches Bank of Uganda / IFRS CoA conventions for growing financial institutions.

---

## Complete GL Code Mapping (old → new)

### ASSETS

| New | Old | Name | L | Control | Postable |
|-----|-----|------|---|---------|----------|
| 10000 | 1000 | ASSETS | 1 | ✓ | ✗ |
| 11000 | 1100 | Current Assets | 2 | ✓ | ✗ |
| 11100 | 1110 | Cash & Cash Equivalents | 3 | ✓ | ✗ |
| 11101 | 1111 | Petty Cash | 4 | ✗ | ✓ |
| 11102 | 1112 | Cash at Bank – Operating Account | 4 | ✗ | ✓ |
| 11103 | 1113 | Cash at Bank – Loan Disbursement | 4 | ✗ | ✓ |
| 11104 | 1114 | Mobile Money – MTN | 4 | ✗ | ✓ |
| 11105 | 1115 | Mobile Money – Airtel | 4 | ✗ | ✓ |
| 11200 | 1120 | Member Savings Control | 3 | ✓ | ✗ |
| 11201 | 1121 | Mandatory Savings – Members | 4 | ✗ | ✓ |
| 11202 | 1122 | Voluntary Savings – Members | 4 | ✗ | ✓ |
| 11203 | 1123 | Fixed Deposit – Members | 4 | ✗ | ✓ |
| 11300 | 1130 | Loans Receivable (Gross) | 3 | ✓ | ✗ |
| 11301 | 1131 | Personal / Consumer Loans | 4 | ✗ | ✓ |
| 11302 | 1132 | Business Loans | 4 | ✗ | ✓ |
| 11303 | 1133 | Agriculture Loans | 4 | ✗ | ✓ |
| 11304 | 1134 | Salary Loans | 4 | ✗ | ✓ |
| 11305 | 1135 | Group / VSLA Loans | 4 | ✗ | ✓ |
| 11400 | 1140 | Loan Loss Provisions (ECL) | 3 | ✓ | ✗ |
| 11401 | 1141 | Stage 1 ECL Provision | 4 | ✗ | ✓ |
| 11402 | 1142 | Stage 2 ECL Provision | 4 | ✗ | ✓ |
| 11403 | 1143 | Stage 3 ECL Provision | 4 | ✗ | ✓ |
| 11500 | 1150 | Interest Receivable | 3 | ✗ | ✓ |
| 11600 | 1155 | Penalty Receivable | 3 | ✗ | ✓ |
| 11700 | 1157 | Charges Receivable | 3 | ✗ | ✓ |
| 11800 | 1160 | Prepayments & Other Receivables | 3 | ✗ | ✓ |
| 11900 | 1170 | Investment in Govt Securities | 3 | ✗ | ✓ |
| 12000 | 1200 | Non-Current Assets | 2 | ✓ | ✗ |
| 12100 | 1210 | Property, Plant & Equipment | 3 | ✓ | ✗ |
| 12101 | 1211 | Land & Buildings (Cost) | 4 | ✗ | ✓ |
| 12102 | 1212 | Motor Vehicles (Cost) | 4 | ✗ | ✓ |
| 12103 | 1213 | Computers & IT Equipment (Cost) | 4 | ✗ | ✓ |
| 12104 | 1214 | Furniture & Fittings (Cost) | 4 | ✗ | ✓ |
| 12200 | 1220 | Accumulated Depreciation | 3 | ✓ | ✗ |
| 12201 | 1221 | Accum Depr – Land & Buildings | 4 | ✗ | ✓ |
| 12202 | 1222 | Accum Depr – Motor Vehicles | 4 | ✗ | ✓ |
| 12203 | 1223 | Accum Depr – IT Equipment | 4 | ✗ | ✓ |
| 12204 | 1224 | Accum Depr – Furniture | 4 | ✗ | ✓ |
| 12300 | 1230 | Intangible Assets | 3 | ✓ | ✗ |
| 12301 | 1231 | Software & Licences (Cost) | 4 | ✗ | ✓ |
| 12302 | 1232 | Accum Amortisation – Software | 4 | ✗ | ✓ |

### LIABILITIES

| New | Old | Name | L | Control | Postable |
|-----|-----|------|---|---------|----------|
| 20000 | 2000 | LIABILITIES | 1 | ✓ | ✗ |
| 21000 | 2100 | Current Liabilities | 2 | ✓ | ✗ |
| 21100 | 2110 | Member Savings Liability | 3 | ✓ | ✗ |
| 21101 | 2111 | Mandatory Savings Deposits | 4 | ✗ | ✓ |
| 21102 | 2112 | Voluntary Savings Deposits | 4 | ✗ | ✓ |
| 21103 | 2113 | Fixed Deposits | 4 | ✗ | ✓ |
| 21200 | 2120 | Share Capital Subscriptions | 3 | ✗ | ✓ |
| 21300 | 2130 | Accrued Interest on Savings | 3 | ✗ | ✓ |
| 21400 | 2140 | Dividends Payable | 3 | ✗ | ✓ |
| 21500 | 2150 | Accounts Payable & Accruals | 3 | ✗ | ✓ |
| 21600 | 2160 | Tax Payable | 3 | ✓ | ✗ |
| 21601 | 2161 | PAYE Payable | 4 | ✗ | ✓ |
| 21602 | 2162 | VAT Payable | 4 | ✗ | ✓ |
| 21603 | 2163 | Withholding Tax Payable | 4 | ✗ | ✓ |
| 22000 | 2200 | Non-Current Liabilities | 2 | ✓ | ✗ |
| 22100 | 2210 | External Borrowings | 3 | ✓ | ✗ |
| 22101 | 2211 | Bank Loans Payable | 4 | ✗ | ✓ |
| 22102 | 2212 | MFI Apex Borrowings | 4 | ✗ | ✓ |

### EQUITY

| New | Old | Name | L | Control | Postable |
|-----|-----|------|---|---------|----------|
| 30000 | 3000 | EQUITY / MEMBERS' FUNDS | 1 | ✓ | ✗ |
| 31000 | 3100 | Share Capital | 2 | ✓ | ✗ |
| 31100 | 3110 | Ordinary Share Capital | 3 | ✗ | ✓ |
| 31200 | 3120 | Share Premium | 3 | ✗ | ✓ |
| 32000 | 3200 | Reserves | 2 | ✓ | ✗ |
| 32100 | 3210 | Statutory Reserve (SACCO Act) | 3 | ✗ | ✓ |
| 32200 | 3220 | Institutional Capital Reserve | 3 | ✗ | ✓ |
| 32300 | 3230 | General Reserve | 3 | ✗ | ✓ |
| 32400 | 3240 | Loan Loss Reserve | 3 | ✗ | ✓ |
| 33000 | 3300 | Retained Earnings / Surplus | 2 | ✓ | ✗ |
| 33100 | 3310 | Retained Earnings – Prior Years | 3 | ✗ | ✓ |
| 33200 | 3320 | Surplus/Deficit – Current Year | 3 | ✗ | ✓ |
| 33300 | 3330 | Dividends Declared | 3 | ✗ | ✓ |
| 33900 | 3390 | Opening Balance Control | 3 | ✗ | ✓ |

### INCOME

| New | Old | Name | L | Control | Postable |
|-----|-----|------|---|---------|----------|
| 40000 | 4000 | INCOME | 1 | ✓ | ✗ |
| 41000 | 4100 | Interest Income | 2 | ✓ | ✗ |
| 41100 | 4110 | Interest on Personal Loans | 3 | ✓ | ✗ |
| 41101 | 4111 | Accrued Interest – Personal Loans | 4 | ✗ | ✓ |
| 41102 | 4112 | Cash Interest – Personal Loans | 4 | ✗ | ✓ |
| 41200 | 4120 | Interest on Business Loans | 3 | ✗ | ✓ |
| 41300 | 4130 | Interest on Agriculture Loans | 3 | ✗ | ✓ |
| 41400 | 4140 | Penalty / Default Interest | 3 | ✗ | ✓ |
| 42000 | 4200 | Fee Income | 2 | ✓ | ✗ |
| 42100 | 4210 | Loan Application Fees | 3 | ✗ | ✓ |
| 42200 | 4220 | Loan Processing Fees | 3 | ✗ | ✓ |
| 42250 | 4225 | Loan Charges Income | 3 | ✗ | ✓ |
| 42300 | 4230 | Account Maintenance Fees | 3 | ✗ | ✓ |
| 42400 | 4240 | Late Payment Penalties | 3 | ✗ | ✓ |
| 43000 | 4300 | Other Operating Income | 2 | ✗ | ✓ |
| 44000 | 4400 | Investment Income | 2 | ✗ | ✓ |

### EXPENSES

| New | Old | Name | L | Control | Postable |
|-----|-----|------|---|---------|----------|
| 50000 | 5000 | EXPENSES | 1 | ✓ | ✗ |
| 51000 | 5100 | Financial Expenses | 2 | ✓ | ✗ |
| 51100 | 5110 | Interest Expense on Savings | 3 | ✗ | ✓ |
| 51200 | 5120 | Interest Expense on Borrowings | 3 | ✗ | ✓ |
| 51300 | 5130 | ECL Provision Expense (IFRS 9) | 3 | ✓ | ✗ |
| 51301 | 5131 | ECL Charge – Stage 1 | 4 | ✗ | ✓ |
| 51302 | 5132 | ECL Charge – Stage 2 | 4 | ✗ | ✓ |
| 51303 | 5133 | ECL Charge – Stage 3 | 4 | ✗ | ✓ |
| 51304 | 5134 | Loan Write-off Expense | 4 | ✗ | ✓ |
| 52000 | 5200 | Staff Costs | 2 | ✓ | ✗ |
| 52100 | 5210 | Salaries & Wages | 3 | ✗ | ✓ |
| 52200 | 5220 | NSSF Contributions | 3 | ✗ | ✓ |
| 52300 | 5230 | Staff Training | 3 | ✗ | ✓ |
| 52400 | 5240 | Medical & Health Insurance | 3 | ✗ | ✓ |
| 53000 | 5300 | Administrative Expenses | 2 | ✓ | ✗ |
| 53100 | 5310 | Rent & Occupancy | 3 | ✗ | ✓ |
| 53200 | 5320 | Utilities | 3 | ✗ | ✓ |
| 53300 | 5330 | Communications & Internet | 3 | ✗ | ✓ |
| 53400 | 5340 | Audit & Professional Fees | 3 | ✗ | ✓ |
| 53500 | 5350 | Regulatory Fees & Levies | 3 | ✗ | ✓ |
| 53600 | 5360 | Board Allowances | 3 | ✗ | ✓ |
| 54000 | 5400 | Depreciation & Amortisation | 2 | ✓ | ✗ |
| 54100 | 5410 | Depreciation – Buildings | 3 | ✗ | ✓ |
| 54200 | 5420 | Depreciation – Motor Vehicles | 3 | ✗ | ✓ |
| 54300 | 5430 | Depreciation – IT Equipment | 3 | ✗ | ✓ |
| 54400 | 5440 | Amortisation – Software | 3 | ✗ | ✓ |
| 55000 | 5500 | Other Operating Expenses | 2 | ✗ | ✓ |
| 56000 | 5600 | Tax Expense | 2 | ✗ | ✓ |

---

## Components

### 1. `SaccoCoaSeeder.php`
Replace all `gl_code` values with 5-digit equivalents per the mapping table above. No structural changes — same array format, same fields.

### 2. Landlord migration — `coa_template_accounts`
New migration in `database/migrations/landlord/`:
- Truncates `coa_template_accounts` for the `SACCO_UGANDA` template
- Re-seeds with 5-digit codes (or alternatively: a batch UPDATE using the mapping table)
- Chosen approach: batch UPDATE to preserve `id` values and `created_at` timestamps

### 3. Tenant migration — `chart_of_accounts`
New migration in `database/migrations/tenant/`:
- Runs a single batch `UPDATE chart_of_accounts SET gl_code = (CASE … END)` covering all 93 accounts
- No other tables are touched — journal entry lines, GL entries, and sub-ledger entries reference accounts by `id` (UUID FK), not by `gl_code` string
- The `gl_code` column is already `varchar(20)`, so 5-digit codes fit with no schema change

### 4. `GlCodes.php` constants class
New file: `app/Tenant/Modules/Accounting/GlCodes.php`

```php
final class GlCodes
{
    // Cash & equivalents
    const PETTY_CASH              = '11101';
    const BANK_OPERATING          = '11102';
    const BANK_LOAN_DISBURSEMENT  = '11103';
    const MOBILE_MONEY_MTN        = '11104';
    const MOBILE_MONEY_AIRTEL     = '11105';

    // Member savings liability
    const SAVINGS_MANDATORY       = '21101';
    const SAVINGS_VOLUNTARY       = '21102';
    const SAVINGS_FIXED_DEPOSIT   = '21103';

    // Equity
    const SHARE_CAPITAL_ORDINARY  = '31100';

    // Fee income
    const FEE_LOAN_PROCESSING     = '42200';
    const FEE_ACCOUNT_MAINTENANCE = '42300';

    // Expenses
    const EXPENSE_LOAN_WRITE_OFF  = '51304';
}
```

### 5. Service file updates (7 files, 11 references)

| File | Old literal | New constant |
|------|-------------|--------------|
| `SavingsCoaResolver` | `'1111'` | `GlCodes::PETTY_CASH` |
| `SavingsCoaResolver` | `'1112'` (default) | `GlCodes::BANK_OPERATING` |
| `SavingsCoaResolver` | `'1114'` | `GlCodes::MOBILE_MONEY_MTN` |
| `SavingsCoaResolver` | `'1115'` | `GlCodes::MOBILE_MONEY_AIRTEL` |
| `SavingsCoaResolver` | `'2111'` | `GlCodes::SAVINGS_MANDATORY` |
| `SavingsCoaResolver` | `'2112'` (default) | `GlCodes::SAVINGS_VOLUNTARY` |
| `SavingsCoaResolver` | `'2113'` | `GlCodes::SAVINGS_FIXED_DEPOSIT` |
| `LoanDisbursementService` | `'2111'`–`'2113'` | `GlCodes::SAVINGS_*` |
| `LoanDisbursementService` | `'4220'` | `GlCodes::FEE_LOAN_PROCESSING` |
| `LoanRepaymentService` | `'2111'`–`'2113'` | `GlCodes::SAVINGS_*` |
| `LoanRescheduleService` | `'2111'` | `GlCodes::SAVINGS_MANDATORY` |
| `FdMaturityAccountingService` | `'2112'`, `'2113'` | `GlCodes::SAVINGS_*` |
| `SavingsJournalService` | `'4230'` (×3) | `GlCodes::FEE_ACCOUNT_MAINTENANCE` |
| `ShareAccountingService` | `'3110'` | `GlCodes::SHARE_CAPITAL_ORDINARY` |
| `LoanWriteOffService` | `'5134'` | `GlCodes::EXPENSE_LOAN_WRITE_OFF` |

---

## Execution Order

1. Update `SaccoCoaSeeder.php`
2. Create landlord migration (update `coa_template_accounts`)
3. Create tenant migration (batch UPDATE `chart_of_accounts`)
4. Create `GlCodes.php`
5. Update 7 service files (replace hardcoded strings with constants)
6. Run `composer test` to verify no regressions

## Out of Scope

- Frontend changes: The frontend displays `gl_code` as a string from the API — it will automatically reflect the new codes with no code changes required
- Any user-added custom accounts outside the standard 93 — those will not be touched by the migration (only the 93 seeded codes are remapped)
