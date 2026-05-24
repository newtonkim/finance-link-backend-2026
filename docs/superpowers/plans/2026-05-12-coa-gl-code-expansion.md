# COA GL Code Expansion (4-digit → 5-digit) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expand all Chart of Accounts GL codes from 4-digit to 5-digit so every Level 3 control account has 99 child slots instead of 9, eliminating collision risk for tenants with many ledgers.

**Architecture:** Three parallel changes land together — (1) the seeder is updated so new tenants get 5-digit codes from day one, (2) a landlord migration patches the `coa_template_accounts` table in the master DB, (3) a tenant migration batch-updates `chart_of_accounts.gl_code` in every tenant DB. A new `GlCodes` constants class replaces all hardcoded GL code strings in service files so future renumbering is a one-line change.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL, Pest

---

## File Map

| Action | File |
|--------|------|
| Modify | `database/seeders/SaccoCoaSeeder.php` |
| Create | `database/migrations/landlord/2026_05_12_000001_remap_coa_template_gl_codes.php` |
| Create | `database/migrations/tenant/2026_05_12_000001_remap_chart_of_accounts_gl_codes.php` |
| Create | `app/Tenant/Modules/Accounting/GlCodes.php` |
| Modify | `app/Tenant/Modules/Accounting/Services/SavingsCoaResolver.php` |
| Modify | `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php` |
| Modify | `app/Tenant/Modules/Loans/Services/LoanRepaymentService.php` |
| Modify | `app/Tenant/Modules/Loans/Services/LoanRescheduleService.php` |
| Modify | `app/Tenant/Modules/Savings/Services/FdMaturityAccountingService.php` |
| Modify | `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` |
| Modify | `app/Tenant/Modules/Loans/Services/LoanWriteOffService.php` |
| Modify | `app/Tenant/Modules/Shares/Services/ShareAccountingService.php` |
| Modify | `tests/Feature/Tenant/ChartOfAccountTest.php` |

---

## Reference: Complete Old → New Mapping

Use this table in every task below. Never derive codes from memory — copy from here.

```
10000 ← 1000   11000 ← 1100   11100 ← 1110   11101 ← 1111   11102 ← 1112
11103 ← 1113   11104 ← 1114   11105 ← 1115   11200 ← 1120   11201 ← 1121
11202 ← 1122   11203 ← 1123   11300 ← 1130   11301 ← 1131   11302 ← 1132
11303 ← 1133   11304 ← 1134   11305 ← 1135   11400 ← 1140   11401 ← 1141
11402 ← 1142   11403 ← 1143   11500 ← 1150   11600 ← 1155   11700 ← 1157
11800 ← 1160   11900 ← 1170   12000 ← 1200   12100 ← 1210   12101 ← 1211
12102 ← 1212   12103 ← 1213   12104 ← 1214   12200 ← 1220   12201 ← 1221
12202 ← 1222   12203 ← 1223   12204 ← 1224   12300 ← 1230   12301 ← 1231
12302 ← 1232   20000 ← 2000   21000 ← 2100   21100 ← 2110   21101 ← 2111
21102 ← 2112   21103 ← 2113   21200 ← 2120   21300 ← 2130   21400 ← 2140
21500 ← 2150   21600 ← 2160   21601 ← 2161   21602 ← 2162   21603 ← 2163
22000 ← 2200   22100 ← 2210   22101 ← 2211   22102 ← 2212   30000 ← 3000
31000 ← 3100   31100 ← 3110   31200 ← 3120   32000 ← 3200   32100 ← 3210
32200 ← 3220   32300 ← 3230   32400 ← 3240   33000 ← 3300   33100 ← 3310
33200 ← 3320   33300 ← 3330   33900 ← 3390   40000 ← 4000   41000 ← 4100
41100 ← 4110   41101 ← 4111   41102 ← 4112   41200 ← 4120   41300 ← 4130
41400 ← 4140   42000 ← 4200   42100 ← 4210   42200 ← 4220   42250 ← 4225
42300 ← 4230   42400 ← 4240   43000 ← 4300   44000 ← 4400   50000 ← 5000
51000 ← 5100   51100 ← 5110   51200 ← 5120   51300 ← 5130   51301 ← 5131
51302 ← 5132   51303 ← 5133   51304 ← 5134   52000 ← 5200   52100 ← 5210
52200 ← 5220   52300 ← 5230   52400 ← 5240   53000 ← 5300   53100 ← 5310
53200 ← 5320   53300 ← 5330   53400 ← 5340   53500 ← 5350   53600 ← 5360
54000 ← 5400   54100 ← 5410   54200 ← 5420   54300 ← 5430   54400 ← 5440
55000 ← 5500   56000 ← 5600
```

---

## Task 1: Update SaccoCoaSeeder

**Files:**
- Modify: `database/seeders/SaccoCoaSeeder.php`

- [ ] **Step 1: Replace all gl_code values with 5-digit codes**

Open `database/seeders/SaccoCoaSeeder.php`. Replace the entire `getSaccoUgandaAccounts()` return array with the following (all other seeder logic stays identical):

```php
private function getSaccoUgandaAccounts(): array
{
    return [
        // ASSETS (1xxxx)
        ['gl_code' => '10000', 'name' => 'ASSETS', 'account_type' => 'ASSET', 'account_subtype' => 'Header', 'normal_balance' => 'DR', 'level' => 1, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '11000', 'name' => 'Current Assets', 'account_type' => 'ASSET', 'account_subtype' => 'Current Asset', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '11100', 'name' => 'Cash & Cash Equivalents', 'account_type' => 'ASSET', 'account_subtype' => 'Current Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '11101', 'name' => 'Petty Cash', 'account_type' => 'ASSET', 'account_subtype' => 'Cash', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11102', 'name' => 'Cash at Bank – Operating Account', 'account_type' => 'ASSET', 'account_subtype' => 'Bank', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11103', 'name' => 'Cash at Bank – Loan Disbursement', 'account_type' => 'ASSET', 'account_subtype' => 'Bank', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11104', 'name' => 'Mobile Money – MTN', 'account_type' => 'ASSET', 'account_subtype' => 'Bank', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11105', 'name' => 'Mobile Money – Airtel', 'account_type' => 'ASSET', 'account_subtype' => 'Bank', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11200', 'name' => 'Member Savings Control', 'account_type' => 'ASSET', 'account_subtype' => 'Current Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '11201', 'name' => 'Mandatory Savings – Members', 'account_type' => 'ASSET', 'account_subtype' => 'Member Savings', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11202', 'name' => 'Voluntary Savings – Members', 'account_type' => 'ASSET', 'account_subtype' => 'Member Savings', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11203', 'name' => 'Fixed Deposit – Members', 'account_type' => 'ASSET', 'account_subtype' => 'Member Savings', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11300', 'name' => 'Loans Receivable (Gross)', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '11301', 'name' => 'Personal / Consumer Loans', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11302', 'name' => 'Business Loans', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11303', 'name' => 'Agriculture Loans', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11304', 'name' => 'Salary Loans', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11305', 'name' => 'Group / VSLA Loans', 'account_type' => 'ASSET', 'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11400', 'name' => 'Loan Loss Provisions (ECL)', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '11401', 'name' => 'Stage 1 ECL Provision', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11402', 'name' => 'Stage 2 ECL Provision', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11403', 'name' => 'Stage 3 ECL Provision', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11500', 'name' => 'Interest Receivable', 'account_type' => 'ASSET', 'account_subtype' => 'Accrued Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11600', 'name' => 'Penalty Receivable', 'account_type' => 'ASSET', 'account_subtype' => 'Accrued Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11700', 'name' => 'Charges Receivable', 'account_type' => 'ASSET', 'account_subtype' => 'Accrued Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11800', 'name' => 'Prepayments & Other Receivables', 'account_type' => 'ASSET', 'account_subtype' => 'Current Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '11900', 'name' => 'Investment in Govt Securities', 'account_type' => 'ASSET', 'account_subtype' => 'Investment', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '12000', 'name' => 'Non-Current Assets', 'account_type' => 'ASSET', 'account_subtype' => 'Non-Current Asset', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '12100', 'name' => 'Property, Plant & Equipment', 'account_type' => 'ASSET', 'account_subtype' => 'Fixed Asset', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '12101', 'name' => 'Land & Buildings (Cost)', 'account_type' => 'ASSET', 'account_subtype' => 'Fixed Asset', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '12102', 'name' => 'Motor Vehicles (Cost)', 'account_type' => 'ASSET', 'account_subtype' => 'Fixed Asset', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '12103', 'name' => 'Computers & IT Equipment (Cost)', 'account_type' => 'ASSET', 'account_subtype' => 'Fixed Asset', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '12104', 'name' => 'Furniture & Fittings (Cost)', 'account_type' => 'ASSET', 'account_subtype' => 'Fixed Asset', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '12200', 'name' => 'Accumulated Depreciation', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '12201', 'name' => 'Accum Depr – Land & Buildings', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '12202', 'name' => 'Accum Depr – Motor Vehicles', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '12203', 'name' => 'Accum Depr – IT Equipment', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '12204', 'name' => 'Accum Depr – Furniture', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '12300', 'name' => 'Intangible Assets', 'account_type' => 'ASSET', 'account_subtype' => 'Intangible', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '12301', 'name' => 'Software & Licences (Cost)', 'account_type' => 'ASSET', 'account_subtype' => 'Intangible', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '12302', 'name' => 'Accum Amortisation – Software', 'account_type' => 'ASSET', 'account_subtype' => 'Contra Asset', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],

        // LIABILITIES (2xxxx)
        ['gl_code' => '20000', 'name' => 'LIABILITIES', 'account_type' => 'LIABILITY', 'account_subtype' => 'Header', 'normal_balance' => 'CR', 'level' => 1, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '21000', 'name' => 'Current Liabilities', 'account_type' => 'LIABILITY', 'account_subtype' => 'Current Liability', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '21100', 'name' => 'Member Savings Liability', 'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '21101', 'name' => 'Mandatory Savings Deposits', 'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '21102', 'name' => 'Voluntary Savings Deposits', 'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '21103', 'name' => 'Fixed Deposits', 'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '21200', 'name' => 'Share Capital Subscriptions', 'account_type' => 'LIABILITY', 'account_subtype' => 'Share Capital', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '21300', 'name' => 'Accrued Interest on Savings', 'account_type' => 'LIABILITY', 'account_subtype' => 'Accrued Liability', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '21400', 'name' => 'Dividends Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Accrued Liability', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '21500', 'name' => 'Accounts Payable & Accruals', 'account_type' => 'LIABILITY', 'account_subtype' => 'Payable', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '21600', 'name' => 'Tax Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Payable', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '21601', 'name' => 'PAYE Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Payable', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '21602', 'name' => 'VAT Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Payable', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '21603', 'name' => 'Withholding Tax Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Payable', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '22000', 'name' => 'Non-Current Liabilities', 'account_type' => 'LIABILITY', 'account_subtype' => 'Non-Current Liability', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '22100', 'name' => 'External Borrowings', 'account_type' => 'LIABILITY', 'account_subtype' => 'Borrowings', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '22101', 'name' => 'Bank Loans Payable', 'account_type' => 'LIABILITY', 'account_subtype' => 'Borrowings', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '22102', 'name' => 'MFI Apex Borrowings', 'account_type' => 'LIABILITY', 'account_subtype' => 'Borrowings', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],

        // EQUITY (3xxxx)
        ['gl_code' => '30000', 'name' => 'EQUITY / MEMBERS\' FUNDS', 'account_type' => 'EQUITY', 'account_subtype' => 'Header', 'normal_balance' => 'CR', 'level' => 1, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '31000', 'name' => 'Share Capital', 'account_type' => 'EQUITY', 'account_subtype' => 'Share Capital', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '31100', 'name' => 'Ordinary Share Capital', 'account_type' => 'EQUITY', 'account_subtype' => 'Share Capital', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '31200', 'name' => 'Share Premium', 'account_type' => 'EQUITY', 'account_subtype' => 'Share Capital', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '32000', 'name' => 'Reserves', 'account_type' => 'EQUITY', 'account_subtype' => 'Reserves', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '32100', 'name' => 'Statutory Reserve (SACCO Act)', 'account_type' => 'EQUITY', 'account_subtype' => 'Regulatory Reserve', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '32200', 'name' => 'Institutional Capital Reserve', 'account_type' => 'EQUITY', 'account_subtype' => 'Regulatory Reserve', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '32300', 'name' => 'General Reserve', 'account_type' => 'EQUITY', 'account_subtype' => 'General Reserve', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '32400', 'name' => 'Loan Loss Reserve', 'account_type' => 'EQUITY', 'account_subtype' => 'General Reserve', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '33000', 'name' => 'Retained Earnings / Surplus', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '33100', 'name' => 'Retained Earnings – Prior Years', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '33200', 'name' => 'Surplus/Deficit – Current Year', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '33300', 'name' => 'Dividends Declared', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '33900', 'name' => 'Opening Balance Control', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],

        // INCOME (4xxxx)
        ['gl_code' => '40000', 'name' => 'INCOME', 'account_type' => 'INCOME', 'account_subtype' => 'Header', 'normal_balance' => 'CR', 'level' => 1, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '41000', 'name' => 'Interest Income', 'account_type' => 'INCOME', 'account_subtype' => 'Operating Income', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '41100', 'name' => 'Interest on Personal Loans', 'account_type' => 'INCOME', 'account_subtype' => 'Loan Interest', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '41101', 'name' => 'Accrued Interest – Personal Loans', 'account_type' => 'INCOME', 'account_subtype' => 'Accrued Income', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '41102', 'name' => 'Cash Interest – Personal Loans', 'account_type' => 'INCOME', 'account_subtype' => 'Cash Income', 'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '41200', 'name' => 'Interest on Business Loans', 'account_type' => 'INCOME', 'account_subtype' => 'Loan Interest', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '41300', 'name' => 'Interest on Agriculture Loans', 'account_type' => 'INCOME', 'account_subtype' => 'Loan Interest', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '41400', 'name' => 'Penalty / Default Interest', 'account_type' => 'INCOME', 'account_subtype' => 'Loan Interest', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '42000', 'name' => 'Fee Income', 'account_type' => 'INCOME', 'account_subtype' => 'Operating Income', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '42100', 'name' => 'Loan Application Fees', 'account_type' => 'INCOME', 'account_subtype' => 'Fee Income', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '42200', 'name' => 'Loan Processing Fees', 'account_type' => 'INCOME', 'account_subtype' => 'Fee Income', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '42250', 'name' => 'Loan Charges Income', 'account_type' => 'INCOME', 'account_subtype' => 'Fee Income', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '42300', 'name' => 'Account Maintenance Fees', 'account_type' => 'INCOME', 'account_subtype' => 'Fee Income', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '42400', 'name' => 'Late Payment Penalties', 'account_type' => 'INCOME', 'account_subtype' => 'Fee Income', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '43000', 'name' => 'Other Operating Income', 'account_type' => 'INCOME', 'account_subtype' => 'Other Income', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '44000', 'name' => 'Investment Income', 'account_type' => 'INCOME', 'account_subtype' => 'Investment Income', 'normal_balance' => 'CR', 'level' => 2, 'is_control' => false, 'is_postable' => true],

        // EXPENSES (5xxxx)
        ['gl_code' => '50000', 'name' => 'EXPENSES', 'account_type' => 'EXPENSE', 'account_subtype' => 'Header', 'normal_balance' => 'DR', 'level' => 1, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '51000', 'name' => 'Financial Expenses', 'account_type' => 'EXPENSE', 'account_subtype' => 'Financial Cost', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '51100', 'name' => 'Interest Expense on Savings', 'account_type' => 'EXPENSE', 'account_subtype' => 'Financial Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '51200', 'name' => 'Interest Expense on Borrowings', 'account_type' => 'EXPENSE', 'account_subtype' => 'Financial Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '51300', 'name' => 'ECL Provision Expense (IFRS 9)', 'account_type' => 'EXPENSE', 'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '51301', 'name' => 'ECL Charge – Stage 1', 'account_type' => 'EXPENSE', 'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '51302', 'name' => 'ECL Charge – Stage 2', 'account_type' => 'EXPENSE', 'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '51303', 'name' => 'ECL Charge – Stage 3', 'account_type' => 'EXPENSE', 'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '51304', 'name' => 'Loan Write-off Expense', 'account_type' => 'EXPENSE', 'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '52000', 'name' => 'Staff Costs', 'account_type' => 'EXPENSE', 'account_subtype' => 'Operating Expense', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '52100', 'name' => 'Salaries & Wages', 'account_type' => 'EXPENSE', 'account_subtype' => 'Staff Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '52200', 'name' => 'NSSF Contributions', 'account_type' => 'EXPENSE', 'account_subtype' => 'Staff Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '52300', 'name' => 'Staff Training', 'account_type' => 'EXPENSE', 'account_subtype' => 'Staff Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '52400', 'name' => 'Medical & Health Insurance', 'account_type' => 'EXPENSE', 'account_subtype' => 'Staff Cost', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '53000', 'name' => 'Administrative Expenses', 'account_type' => 'EXPENSE', 'account_subtype' => 'Operating Expense', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '53100', 'name' => 'Rent & Occupancy', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '53200', 'name' => 'Utilities', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '53300', 'name' => 'Communications & Internet', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '53400', 'name' => 'Audit & Professional Fees', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '53500', 'name' => 'Regulatory Fees & Levies', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '53600', 'name' => 'Board Allowances', 'account_type' => 'EXPENSE', 'account_subtype' => 'Admin Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '54000', 'name' => 'Depreciation & Amortisation', 'account_type' => 'EXPENSE', 'account_subtype' => 'Non-Cash Expense', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => true, 'is_postable' => false],
        ['gl_code' => '54100', 'name' => 'Depreciation – Buildings', 'account_type' => 'EXPENSE', 'account_subtype' => 'Non-Cash Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '54200', 'name' => 'Depreciation – Motor Vehicles', 'account_type' => 'EXPENSE', 'account_subtype' => 'Non-Cash Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '54300', 'name' => 'Depreciation – IT Equipment', 'account_type' => 'EXPENSE', 'account_subtype' => 'Non-Cash Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '54400', 'name' => 'Amortisation – Software', 'account_type' => 'EXPENSE', 'account_subtype' => 'Non-Cash Expense', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '55000', 'name' => 'Other Operating Expenses', 'account_type' => 'EXPENSE', 'account_subtype' => 'Operating Expense', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => false, 'is_postable' => true],
        ['gl_code' => '56000', 'name' => 'Tax Expense', 'account_type' => 'EXPENSE', 'account_subtype' => 'Tax', 'normal_balance' => 'DR', 'level' => 2, 'is_control' => false, 'is_postable' => true],
    ];
}
```

- [ ] **Step 2: Commit**

```bash
git add database/seeders/SaccoCoaSeeder.php
git commit -m "feat: update SaccoCoaSeeder to 5-digit GL codes"
```

---

## Task 2: Create GlCodes constants class

**Files:**
- Create: `app/Tenant/Modules/Accounting/GlCodes.php`

- [ ] **Step 1: Create the file**

```php
<?php

namespace App\Tenant\Modules\Accounting;

final class GlCodes
{
    // Cash & equivalents
    const PETTY_CASH             = '11101';
    const BANK_OPERATING         = '11102';
    const BANK_LOAN_DISBURSEMENT = '11103';
    const MOBILE_MONEY_MTN       = '11104';
    const MOBILE_MONEY_AIRTEL    = '11105';

    // Member savings liability
    const SAVINGS_MANDATORY      = '21101';
    const SAVINGS_VOLUNTARY      = '21102';
    const SAVINGS_FIXED_DEPOSIT  = '21103';

    // Equity
    const SHARE_CAPITAL_ORDINARY = '31100';
    const OPENING_BALANCE_CONTROL = '33900';

    // Fee income
    const FEE_LOAN_PROCESSING    = '42200';
    const FEE_ACCOUNT_MAINTENANCE = '42300';

    // Expenses
    const EXPENSE_LOAN_WRITE_OFF = '51304';
}
```

- [ ] **Step 2: Write a unit test**

Create `tests/Unit/GlCodesTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Tenant\Modules\Accounting\GlCodes;
use PHPUnit\Framework\TestCase;

class GlCodesTest extends TestCase
{
    public function test_all_constants_are_five_digits(): void
    {
        $reflection = new \ReflectionClass(GlCodes::class);
        foreach ($reflection->getConstants() as $name => $value) {
            $this->assertMatchesRegularExpression(
                '/^\d{5}$/',
                $value,
                "GlCodes::{$name} = '{$value}' is not a 5-digit string"
            );
        }
    }

    public function test_constants_are_unique(): void
    {
        $reflection = new \ReflectionClass(GlCodes::class);
        $values = array_values($reflection->getConstants());
        $this->assertCount(count(array_unique($values)), $values, 'Duplicate GL code constant values found');
    }
}
```

- [ ] **Step 3: Run the test**

```bash
php artisan test --filter=GlCodesTest
```

Expected: 2 tests pass.

- [ ] **Step 4: Commit**

```bash
git add app/Tenant/Modules/Accounting/GlCodes.php tests/Unit/GlCodesTest.php
git commit -m "feat: add GlCodes constants class with 5-digit codes"
```

---

## Task 3: Create landlord migration (coa_template_accounts)

**Files:**
- Create: `database/migrations/landlord/2026_05_12_000001_remap_coa_template_gl_codes.php`

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $map = [
        '1000' => '10000', '1100' => '11000', '1110' => '11100', '1111' => '11101',
        '1112' => '11102', '1113' => '11103', '1114' => '11104', '1115' => '11105',
        '1120' => '11200', '1121' => '11201', '1122' => '11202', '1123' => '11203',
        '1130' => '11300', '1131' => '11301', '1132' => '11302', '1133' => '11303',
        '1134' => '11304', '1135' => '11305', '1140' => '11400', '1141' => '11401',
        '1142' => '11402', '1143' => '11403', '1150' => '11500', '1155' => '11600',
        '1157' => '11700', '1160' => '11800', '1170' => '11900', '1200' => '12000',
        '1210' => '12100', '1211' => '12101', '1212' => '12102', '1213' => '12103',
        '1214' => '12104', '1220' => '12200', '1221' => '12201', '1222' => '12202',
        '1223' => '12203', '1224' => '12204', '1230' => '12300', '1231' => '12301',
        '1232' => '12302', '2000' => '20000', '2100' => '21000', '2110' => '21100',
        '2111' => '21101', '2112' => '21102', '2113' => '21103', '2120' => '21200',
        '2130' => '21300', '2140' => '21400', '2150' => '21500', '2160' => '21600',
        '2161' => '21601', '2162' => '21602', '2163' => '21603', '2200' => '22000',
        '2210' => '22100', '2211' => '22101', '2212' => '22102', '3000' => '30000',
        '3100' => '31000', '3110' => '31100', '3120' => '31200', '3200' => '32000',
        '3210' => '32100', '3220' => '32200', '3230' => '32300', '3240' => '32400',
        '3300' => '33000', '3310' => '33100', '3320' => '33200', '3330' => '33300',
        '3390' => '33900', '4000' => '40000', '4100' => '41000', '4110' => '41100',
        '4111' => '41101', '4112' => '41102', '4120' => '41200', '4130' => '41300',
        '4140' => '41400', '4200' => '42000', '4210' => '42100', '4220' => '42200',
        '4225' => '42250', '4230' => '42300', '4240' => '42400', '4300' => '43000',
        '4400' => '44000', '5000' => '50000', '5100' => '51000', '5110' => '51100',
        '5120' => '51200', '5130' => '51300', '5131' => '51301', '5132' => '51302',
        '5133' => '51303', '5134' => '51304', '5200' => '52000', '5210' => '52100',
        '5220' => '52200', '5230' => '52300', '5240' => '52400', '5300' => '53000',
        '5310' => '53100', '5320' => '53200', '5330' => '53300', '5340' => '53400',
        '5350' => '53500', '5360' => '53600', '5400' => '54000', '5410' => '54100',
        '5420' => '54200', '5430' => '54300', '5440' => '54400', '5500' => '55000',
        '5600' => '56000',
    ];

    public function up(): void
    {
        foreach ($this->map as $old => $new) {
            DB::connection('master')
                ->table('coa_template_accounts')
                ->where('gl_code', $old)
                ->update(['gl_code' => $new]);
        }
    }

    public function down(): void
    {
        foreach (array_flip($this->map) as $new => $old) {
            DB::connection('master')
                ->table('coa_template_accounts')
                ->where('gl_code', $new)
                ->update(['gl_code' => $old]);
        }
    }
};
```

- [ ] **Step 2: Run the landlord migration**

```bash
composer migrate:landlord
```

Expected output: `Migrating: 2026_05_12_000001_remap_coa_template_gl_codes` then `Migrated`.

- [ ] **Step 3: Verify spot-check in database**

```bash
php artisan tinker --execute="echo DB::connection('master')->table('coa_template_accounts')->where('gl_code', '11101')->value('name');"
```

Expected: `Petty Cash`

- [ ] **Step 4: Commit**

```bash
git add database/migrations/landlord/2026_05_12_000001_remap_coa_template_gl_codes.php
git commit -m "feat: landlord migration remaps coa_template_accounts to 5-digit GL codes"
```

---

## Task 4: Create tenant migration (chart_of_accounts)

**Files:**
- Create: `database/migrations/tenant/2026_05_12_000001_remap_chart_of_accounts_gl_codes.php`

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $map = [
        '1000' => '10000', '1100' => '11000', '1110' => '11100', '1111' => '11101',
        '1112' => '11102', '1113' => '11103', '1114' => '11104', '1115' => '11105',
        '1120' => '11200', '1121' => '11201', '1122' => '11202', '1123' => '11203',
        '1130' => '11300', '1131' => '11301', '1132' => '11302', '1133' => '11303',
        '1134' => '11304', '1135' => '11305', '1140' => '11400', '1141' => '11401',
        '1142' => '11402', '1143' => '11403', '1150' => '11500', '1155' => '11600',
        '1157' => '11700', '1160' => '11800', '1170' => '11900', '1200' => '12000',
        '1210' => '12100', '1211' => '12101', '1212' => '12102', '1213' => '12103',
        '1214' => '12104', '1220' => '12200', '1221' => '12201', '1222' => '12202',
        '1223' => '12203', '1224' => '12204', '1230' => '12300', '1231' => '12301',
        '1232' => '12302', '2000' => '20000', '2100' => '21000', '2110' => '21100',
        '2111' => '21101', '2112' => '21102', '2113' => '21103', '2120' => '21200',
        '2130' => '21300', '2140' => '21400', '2150' => '21500', '2160' => '21600',
        '2161' => '21601', '2162' => '21602', '2163' => '21603', '2200' => '22000',
        '2210' => '22100', '2211' => '22101', '2212' => '22102', '3000' => '30000',
        '3100' => '31000', '3110' => '31100', '3120' => '31200', '3200' => '32000',
        '3210' => '32100', '3220' => '32200', '3230' => '32300', '3240' => '32400',
        '3300' => '33000', '3310' => '33100', '3320' => '33200', '3330' => '33300',
        '3390' => '33900', '4000' => '40000', '4100' => '41000', '4110' => '41100',
        '4111' => '41101', '4112' => '41102', '4120' => '41200', '4130' => '41300',
        '4140' => '41400', '4200' => '42000', '4210' => '42100', '4220' => '42200',
        '4225' => '42250', '4230' => '42300', '4240' => '42400', '4300' => '43000',
        '4400' => '44000', '5000' => '50000', '5100' => '51000', '5110' => '51100',
        '5120' => '51200', '5130' => '51300', '5131' => '51301', '5132' => '51302',
        '5133' => '51303', '5134' => '51304', '5200' => '52000', '5210' => '52100',
        '5220' => '52200', '5230' => '52300', '5240' => '52400', '5300' => '53000',
        '5310' => '53100', '5320' => '53200', '5330' => '53300', '5340' => '53400',
        '5350' => '53500', '5360' => '53600', '5400' => '54000', '5410' => '54100',
        '5420' => '54200', '5430' => '54300', '5440' => '54400', '5500' => '55000',
        '5600' => '56000',
    ];

    public function up(): void
    {
        foreach ($this->map as $old => $new) {
            DB::connection('tenant')
                ->table('chart_of_accounts')
                ->where('gl_code', $old)
                ->update(['gl_code' => $new]);
        }
    }

    public function down(): void
    {
        foreach (array_flip($this->map) as $new => $old) {
            DB::connection('tenant')
                ->table('chart_of_accounts')
                ->where('gl_code', $new)
                ->update(['gl_code' => $old]);
        }
    }
};
```

- [ ] **Step 2: Run tenant migrations across all tenants**

```bash
composer migrate:tenants
```

Expected: migration runs on every tenant DB without errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/tenant/2026_05_12_000001_remap_chart_of_accounts_gl_codes.php
git commit -m "feat: tenant migration remaps chart_of_accounts to 5-digit GL codes"
```

---

## Task 5: Update SavingsCoaResolver

**Files:**
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsCoaResolver.php`

- [ ] **Step 1: Replace hardcoded strings with GlCodes constants**

Replace the entire file content:

```php
<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Contracts\SavingsCoaResolverInterface;
use App\Tenant\Modules\Accounting\GlCodes;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

class SavingsCoaResolver implements SavingsCoaResolverInterface
{
    public function resolvePaymentModeAccount(string $mode): ChartOfAccount
    {
        $glCode = match (strtolower(trim($mode))) {
            'cash', 'petty_cash'                     => GlCodes::PETTY_CASH,
            'mobile_money', 'mtn', 'mobile_money_mtn' => GlCodes::MOBILE_MONEY_MTN,
            'airtel', 'mobile_money_airtel'           => GlCodes::MOBILE_MONEY_AIRTEL,
            default                                   => GlCodes::BANK_OPERATING,
        };

        return $this->resolveByGlCode($glCode);
    }

    public function resolveSavingsLiabilityAccount(SavingsAccount $account): ChartOfAccount
    {
        $accountType = strtolower($account->account_type ?? '');
        $productType = strtolower($account->savingsProduct?->type ?? '');
        $type = $accountType ?: $productType;

        $glCode = match (true) {
            str_contains($type, 'mandatory') => GlCodes::SAVINGS_MANDATORY,
            str_contains($type, 'fixed')     => GlCodes::SAVINGS_FIXED_DEPOSIT,
            default                          => GlCodes::SAVINGS_VOLUNTARY,
        };

        return $this->resolveByGlCode($glCode);
    }

    public function resolveByGlCode(string $glCode): ChartOfAccount
    {
        $account = ChartOfAccount::where('gl_code', $glCode)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new \RuntimeException(
                "COA entry missing for GL code {$glCode}. Run the Chart of Accounts seeder."
            );
        }

        return $account;
    }
}
```

- [ ] **Step 2: Run existing tests**

```bash
php artisan test --filter=ChartOfAccountTest
```

Expected: all 5 tests pass.

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Accounting/Services/SavingsCoaResolver.php
git commit -m "refactor: replace hardcoded GL codes in SavingsCoaResolver with GlCodes constants"
```

---

## Task 6: Update LoanDisbursementService

**Files:**
- Modify: `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php`

- [ ] **Step 1: Add GlCodes import**

At the top of the file, after the existing `use` statements, add:

```php
use App\Tenant\Modules\Accounting\GlCodes;
```

- [ ] **Step 2: Update resolveSavingsLiabilityGlCode (lines 748–752)**

Replace:
```php
        return match (true) {
            str_contains($type, 'mandatory') => '2111',
            str_contains($type, 'fixed') => '2113',
            default => '2112',
        };
```

With:
```php
        return match (true) {
            str_contains($type, 'mandatory') => GlCodes::SAVINGS_MANDATORY,
            str_contains($type, 'fixed')     => GlCodes::SAVINGS_FIXED_DEPOSIT,
            default                          => GlCodes::SAVINGS_VOLUNTARY,
        };
```

- [ ] **Step 3: Update fallback lookup (line 824)**

Replace:
```php
                ->where('gl_code', '2111')
```

With:
```php
                ->where('gl_code', GlCodes::SAVINGS_MANDATORY)
```

- [ ] **Step 4: Update postCashChargeCollectionJE (line 850)**

Replace:
```php
        $cashGl = ChartOfAccount::on('tenant')->where('gl_code', '1111')->first();
```

With:
```php
        $cashGl = ChartOfAccount::on('tenant')->where('gl_code', GlCodes::PETTY_CASH)->first();
```

- [ ] **Step 5: Update subledger entry lookup (line 1178)**

Replace:
```php
        $savingsGl = ChartOfAccount::on('tenant')->where('gl_code', '2111')->first();
```

With:
```php
        $savingsGl = ChartOfAccount::on('tenant')->where('gl_code', GlCodes::SAVINGS_MANDATORY)->first();
```

- [ ] **Step 6: Update resolveProcessingFeeAccountId (line 1291)**

Replace:
```php
            ->where('gl_code', '4220')
```

With:
```php
            ->where('gl_code', GlCodes::FEE_LOAN_PROCESSING)
```

- [ ] **Step 7: Commit**

```bash
git add app/Tenant/Modules/Loans/Services/LoanDisbursementService.php
git commit -m "refactor: replace hardcoded GL codes in LoanDisbursementService with GlCodes constants"
```

---

## Task 7: Update LoanRepaymentService

**Files:**
- Modify: `app/Tenant/Modules/Loans/Services/LoanRepaymentService.php`

- [ ] **Step 1: Add GlCodes import**

After existing `use` statements add:

```php
use App\Tenant\Modules\Accounting\GlCodes;
```

- [ ] **Step 2: Update savings GL code match (lines 539–543)**

Replace:
```php
        $glCode = match (true) {
            str_contains($productType, 'mandatory') => '2111',
            str_contains($productType, 'fixed') => '2113',
            default => '2112',
        };
```

With:
```php
        $glCode = match (true) {
            str_contains($productType, 'mandatory') => GlCodes::SAVINGS_MANDATORY,
            str_contains($productType, 'fixed')     => GlCodes::SAVINGS_FIXED_DEPOSIT,
            default                                 => GlCodes::SAVINGS_VOLUNTARY,
        };
```

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Loans/Services/LoanRepaymentService.php
git commit -m "refactor: replace hardcoded GL codes in LoanRepaymentService with GlCodes constants"
```

---

## Task 8: Update LoanRescheduleService

**Files:**
- Modify: `app/Tenant/Modules/Loans/Services/LoanRescheduleService.php`

- [ ] **Step 1: Add GlCodes import**

After existing `use` statements add:

```php
use App\Tenant\Modules\Accounting\GlCodes;
```

- [ ] **Step 2: Update savings GL lookup (line 560)**

Replace:
```php
                    ->where('gl_code', '2111')
```

With:
```php
                    ->where('gl_code', GlCodes::SAVINGS_MANDATORY)
```

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Loans/Services/LoanRescheduleService.php
git commit -m "refactor: replace hardcoded GL codes in LoanRescheduleService with GlCodes constants"
```

---

## Task 9: Update FdMaturityAccountingService

**Files:**
- Modify: `app/Tenant/Modules/Savings/Services/FdMaturityAccountingService.php`

- [ ] **Step 1: Add GlCodes import**

After existing `use` statements add:

```php
use App\Tenant\Modules\Accounting\GlCodes;
```

- [ ] **Step 2: Replace all three resolveByGlCode('2113') calls (lines 35, 65, 99)**

Replace each occurrence of:
```php
$fdGl = $this->coa->resolveByGlCode('2113');
```

With:
```php
$fdGl = $this->coa->resolveByGlCode(GlCodes::SAVINGS_FIXED_DEPOSIT);
```

- [ ] **Step 3: Replace resolveByGlCode('2112') call (line 100)**

Replace:
```php
$volGl = $this->coa->resolveByGlCode('2112');
```

With:
```php
$volGl = $this->coa->resolveByGlCode(GlCodes::SAVINGS_VOLUNTARY);
```

- [ ] **Step 4: Commit**

```bash
git add app/Tenant/Modules/Savings/Services/FdMaturityAccountingService.php
git commit -m "refactor: replace hardcoded GL codes in FdMaturityAccountingService with GlCodes constants"
```

---

## Task 10: Update SavingsJournalService

**Files:**
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php`

- [ ] **Step 1: Add GlCodes import**

After existing `use` statements add:

```php
use App\Tenant\Modules\Accounting\GlCodes;
```

- [ ] **Step 2: Replace resolveByGlCode('4230') at line 85**

Replace:
```php
                    ->first() ?? $this->coa->resolveByGlCode('4230'))
                : $this->coa->resolveByGlCode('4230');
```

With:
```php
                    ->first() ?? $this->coa->resolveByGlCode(GlCodes::FEE_ACCOUNT_MAINTENANCE))
                : $this->coa->resolveByGlCode(GlCodes::FEE_ACCOUNT_MAINTENANCE);
```

- [ ] **Step 3: Replace resolveByGlCode('3390') at line 109**

Replace:
```php
            $retained = $this->coa->resolveByGlCode('3390'); // Opening Balance Control
```

With:
```php
            $retained = $this->coa->resolveByGlCode(GlCodes::OPENING_BALANCE_CONTROL);
```

- [ ] **Step 4: Replace resolveByGlCode('4230') at line 253**

Replace:
```php
            $debitAccount ??= $this->coa->resolveByGlCode('4230');
```

With:
```php
            $debitAccount ??= $this->coa->resolveByGlCode(GlCodes::FEE_ACCOUNT_MAINTENANCE);
```

- [ ] **Step 5: Commit**

```bash
git add app/Tenant/Modules/Accounting/Services/SavingsJournalService.php
git commit -m "refactor: replace hardcoded GL codes in SavingsJournalService with GlCodes constants"
```

---

## Task 11: Update LoanWriteOffService and ShareAccountingService

**Files:**
- Modify: `app/Tenant/Modules/Loans/Services/LoanWriteOffService.php`
- Modify: `app/Tenant/Modules/Shares/Services/ShareAccountingService.php`

- [ ] **Step 1: Update LoanWriteOffService — add import and replace code (line 48)**

Add after existing `use` statements:
```php
use App\Tenant\Modules\Accounting\GlCodes;
```

Replace:
```php
        $writeOffAccountId = ChartOfAccount::where('gl_code', '5134')
```

With:
```php
        $writeOffAccountId = ChartOfAccount::where('gl_code', GlCodes::EXPENSE_LOAN_WRITE_OFF)
```

- [ ] **Step 2: Update ShareAccountingService — add import and replace code (line 38)**

Add after existing `use` statements:
```php
use App\Tenant\Modules\Accounting\GlCodes;
```

Replace:
```php
        $shareCapitalGl = ChartOfAccount::on('tenant')->where('gl_code', '3110')->first();
```

With:
```php
        $shareCapitalGl = ChartOfAccount::on('tenant')->where('gl_code', GlCodes::SHARE_CAPITAL_ORDINARY)->first();
```

Also update the comment on line 28 from `GL 3110` to `GL 31100`:
```php
     *   CR  Ordinary Share Capital (31100)   = total_value
```

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Loans/Services/LoanWriteOffService.php \
        app/Tenant/Modules/Shares/Services/ShareAccountingService.php
git commit -m "refactor: replace hardcoded GL codes in LoanWriteOffService and ShareAccountingService"
```

---

## Task 12: Update test fixtures and run full suite

**Files:**
- Modify: `tests/Feature/Tenant/ChartOfAccountTest.php`

- [ ] **Step 1: Update test GL codes to 5-digit format**

The existing test creates accounts with 4-digit codes (`1000`, `1100`, etc.) which are fine as custom accounts — they won't collide since the seeded accounts now use 5-digit codes. However update the test codes to use 5-digit format to align with the new scheme and avoid any future confusion:

Replace the entire file:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

class ChartOfAccountTest extends TenantTestCase
{
    protected Staff $staff;

    protected string $domain = 'test-sacco.mfukopro.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = Staff::create([
            'name' => 'Tenant Admin',
            'email' => 'admin@test-sacco.com',
            'password' => Hash::make('password'),
            'role' => 'Admin',
            'is_tenant_admin' => true,
        ]);
    }

    public function test_staff_can_view_chart_of_accounts(): void
    {
        ChartOfAccount::create([
            'gl_code' => '10000',
            'name' => 'Asset Account',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'level' => 1,
            'is_control' => true,
        ]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->get('/api/v1/tenant/chart-of-accounts');

        $response->assertStatus(200);
    }

    public function test_staff_can_create_account(): void
    {
        $data = [
            'gl_code' => '11000',
            'name' => 'Cash at Hand',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'is_postable' => true,
            'is_active' => true,
        ];

        $response = $this->actingAs($this->staff, 'tenant')
            ->post('/api/v1/tenant/chart-of-accounts', $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('chart_of_accounts', [
            'gl_code' => '11000',
            'name' => 'Cash at Hand',
        ]);
    }

    public function test_staff_can_update_account(): void
    {
        $account = ChartOfAccount::create([
            'gl_code' => '12000',
            'name' => 'Old Name',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'level' => 1,
        ]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->put("/api/v1/tenant/chart-of-accounts/{$account->id}", [
                'gl_code' => '12000',
                'name' => 'New Name',
                'account_type' => 'ASSET',
                'normal_balance' => 'DR',
            ]);

        $response->assertOk();
        $this->assertEquals('New Name', $account->fresh()->name);
    }

    public function test_staff_can_delete_account(): void
    {
        $account = ChartOfAccount::create([
            'gl_code' => '13000',
            'name' => 'To Delete',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'level' => 1,
        ]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->delete("/api/v1/tenant/chart-of-accounts/{$account->id}");

        $response->assertOk();
        $this->assertSoftDeleted('chart_of_accounts', ['id' => $account->id]);
    }

    public function test_cannot_delete_account_with_children(): void
    {
        $parent = ChartOfAccount::create([
            'gl_code' => '10000',
            'name' => 'Parent',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'level' => 1,
        ]);

        ChartOfAccount::create([
            'gl_code' => '10001',
            'name' => 'Child',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'parent_id' => $parent->id,
            'level' => 2,
        ]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->delete("/api/v1/tenant/chart-of-accounts/{$parent->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $parent->id, 'deleted_at' => null]);
    }
}
```

- [ ] **Step 2: Run the full test suite**

```bash
composer test
```

Expected: all tests pass, lint clean.

- [ ] **Step 3: Verify no remaining 4-digit hardcoded GL codes in service files**

```bash
grep -rn "'[1-5][0-9]\{3\}'" app/Tenant/Modules --include="*.php" \
  | grep -v "//\|#\|max:\|min:\|between\|per_page\|sort_order\|level\|status\|size"
```

Expected: no output (zero matches).

- [ ] **Step 4: Final commit**

```bash
git add tests/Feature/Tenant/ChartOfAccountTest.php
git commit -m "test: update ChartOfAccountTest fixtures to 5-digit GL codes"
```

- [ ] **Step 5: Push branch**

```bash
git push origin fix/journal-entry-actions
```
