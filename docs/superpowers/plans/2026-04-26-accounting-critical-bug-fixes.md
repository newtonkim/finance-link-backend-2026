# Accounting Critical Bug Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix three critical accounting bugs that silently post journal entries to wrong GL accounts, corrupting the general ledger on every affected transaction.

**Architecture:** Each bug lives in a specific service. Bug #1 is fixed by persisting the charge credit account on the Transaction record so the reversal fallback can use the correct GL. Bugs #2 and #3 are fixed by extracting a shared savings-liability GL resolver inside `LoanDisbursementService` so the correct `2111/2112/2113` code is always chosen based on the actual savings product type.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL (tenant DB), Pest (tests). Run tests with `php artisan test --filter=TestName`. Run linting with `composer lint`.

---

## Background — What Is Broken and Why

### Bug #1 — Charge reversal always debits GL 4230 regardless of original income account

**File:** `app/Tenant/Modules/Accounting/Services/AccountingService.php:174`

`postChargeReversal()` hardcodes GL `4230` (Account Maintenance Fees) as the debit account. But charges can be posted to any income account — `MemberChargeService` passes `$creditAccountId` (from `general_charges.credit_account_id`) when calling `postDepositCharge()`. When the reversal fallback runs (no matching JE found), it always debits `4230` regardless of what was originally credited.

**Impact:** The actual income account is never reduced; `4230` gets an incorrect debit; the trial balance cannot foot for SACCOs with multi-account charge configurations.

**Fix strategy:** Add a nullable `gl_credit_account_id` column to `transactions` so every charge transaction records which income GL was credited. The reversal fallback then reads that column and passes it as `$debitAccountId` to `postChargeReversal()`.

> Note: Both `TransactionController::postReversalAccounting()` and `ReversalService::postReversalAccounting()` have a *primary path* that finds the original JE by reference and calls `reverseJournalEntry()`, which is already correct. The bug only hits the *fallback path* (no JE found). The fix ensures the fallback is also correct for all new transactions.

---

### Bug #2 — Savings-channel loan disbursement always credits GL 2111 (Mandatory Savings)

**File:** `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php:707`

`resolveDisbursementCrAccount()` hardcodes `2111` for the `savings_account` disbursement channel. The correct GL depends on the savings product type: `2111` = Mandatory, `2112` = Voluntary (default), `2113` = Fixed Deposit.

**Impact:** If a loan is disbursed into a voluntary savings account, the credit goes to GL `2111` (Mandatory Savings) instead of `2112` (Voluntary Savings). The member's balance is correct but the general ledger is wrong.

---

### Bug #3 — `debit_savings` charge deduction mode always debits GL 2111

**File:** `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php:767`

`postSavingsChargeDeductionJE()` also hardcodes `2111`. Same root cause and impact as Bug #2.

---

## File Map

| File | Action | Reason |
|---|---|---|
| `database/migrations/tenant/2026_04_26_100001_add_gl_credit_account_id_to_transactions_table.php` | **Create** | Bug #1 — new column on transactions |
| `app/Tenant/Modules/Transactions/Models/Transaction.php` | **Modify** | Bug #1 — add `gl_credit_account_id` to `$fillable` |
| `app/Tenant/Modules/Accounting/Services/AccountingService.php` | **Modify** | Bug #1 — add `$debitAccountId` param to `postChargeReversal()` |
| `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php` | **Modify** | Bug #1 — store `gl_credit_account_id` when posting manual charge |
| `app/Tenant/Modules/Members/Services/MemberChargeService.php` | **Modify** | Bug #1 — store `gl_credit_account_id` when collecting charge |
| `app/Tenant/Http/Controllers/Api/V1/TransactionController.php` | **Modify** | Bug #1 — pass `gl_credit_account_id` to reversal fallback |
| `app/Tenant/Modules/Transactions/Services/ReversalService.php` | **Modify** | Bug #1 — pass `gl_credit_account_id` to reversal fallback |
| `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php` | **Modify** | Bugs #2 & #3 — extract `resolveSavingsLiabilityGlCode()`, fix both callers |
| `tests/Feature/Accounting/ChargeReversalGlTest.php` | **Create** | Bug #1 tests |
| `tests/Feature/Accounting/LoanDisbursementGlTest.php` | **Create** | Bugs #2 & #3 tests |

---

## Task 1 — Add `gl_credit_account_id` column to transactions (Bug #1 foundation)

**Files:**
- Create: `database/migrations/tenant/2026_04_26_100001_add_gl_credit_account_id_to_transactions_table.php`
- Modify: `app/Tenant/Modules/Transactions/Models/Transaction.php`

- [ ] **Step 1.1 — Create the migration file**

```php
<?php
// database/migrations/tenant/2026_04_26_100001_add_gl_credit_account_id_to_transactions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('gl_credit_account_id')->nullable()->after('charge_name');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->dropColumn('gl_credit_account_id');
        });
    }
};
```

- [ ] **Step 1.2 — Add `gl_credit_account_id` to Transaction `$fillable`**

Open `app/Tenant/Modules/Transactions/Models/Transaction.php`. The `$fillable` array currently ends with `'branch_id'`. Add `'gl_credit_account_id'` after `'charge_name'`:

```php
protected $fillable = [
    'reference',
    'receipt_number',
    'member_id',
    'type',
    'amount',
    'charge_amount',
    'amount_before_transactions',
    'payment_mode',
    'deposited_by',
    'transaction_date',
    'account_id',
    'account_type',
    'narration',
    'charge_name',
    'gl_credit_account_id',   // ← add this line
    'is_reversible',
    'is_migrated',
    'is_reversed',
    'reversal_of',
    'grouped_with',
    'created_by',
    'branch_id',
];
```

- [ ] **Step 1.3 — Run the migration**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
php artisan tenants:migrate
```

Expected: migration runs without errors on all tenant databases.

- [ ] **Step 1.4 — Commit**

```bash
git add database/migrations/tenant/2026_04_26_100001_add_gl_credit_account_id_to_transactions_table.php \
        app/Tenant/Modules/Transactions/Models/Transaction.php
git commit -m "feat: add gl_credit_account_id to transactions for charge reversal accuracy"
```

---

## Task 2 — Fix `postChargeReversal()` to accept the correct debit account (Bug #1 core fix)

**Files:**
- Modify: `app/Tenant/Modules/Accounting/Services/AccountingService.php:174-211`

- [ ] **Step 2.1 — Write the failing test**

Create `tests/Feature/Accounting/ChargeReversalGlTest.php`:

```php
<?php

namespace Tests\Feature\Accounting;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Services\AccountingService;
use Tests\TenantTestCase;

class ChargeReversalGlTest extends TenantTestCase
{
    private AccountingService $service;
    private ChartOfAccount $maintenanceFees;   // GL 4230
    private ChartOfAccount $applicationFees;   // GL 4210 — a different income account

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AccountingService();

        $this->maintenanceFees = ChartOfAccount::where('gl_code', '4230')->firstOrFail();
        $this->applicationFees = ChartOfAccount::where('gl_code', '4210')->firstOrFail();
    }

    /** Reversal of a charge originally credited to 4230 must debit 4230. */
    public function test_charge_reversal_debits_original_income_account_4230(): void
    {
        $this->service->postChargeReversal(
            memberId: 1,
            savingsAccountId: 1,
            amount: 500.00,
            reference: 'REV-TEST-001',
            date: now()->toDateString(),
            debitAccountId: $this->maintenanceFees->id,
        );

        $gl = GeneralLedger::where('account_id', $this->maintenanceFees->id)
            ->where('debit', 500.00)
            ->first();

        $this->assertNotNull($gl, 'GL 4230 must be debited for the reversal');
    }

    /** Reversal of a charge originally credited to 4210 must debit 4210, NOT 4230. */
    public function test_charge_reversal_debits_custom_income_account_not_4230(): void
    {
        $this->service->postChargeReversal(
            memberId: 1,
            savingsAccountId: 1,
            amount: 300.00,
            reference: 'REV-TEST-002',
            date: now()->toDateString(),
            debitAccountId: $this->applicationFees->id,
        );

        // GL 4210 must be debited
        $correctGl = GeneralLedger::where('account_id', $this->applicationFees->id)
            ->where('debit', 300.00)
            ->first();
        $this->assertNotNull($correctGl, 'GL 4210 must be debited');

        // GL 4230 must NOT be debited
        $wrongGl = GeneralLedger::where('account_id', $this->maintenanceFees->id)
            ->where('debit', 300.00)
            ->first();
        $this->assertNull($wrongGl, 'GL 4230 must NOT be debited when a different account is specified');
    }

    /** When debitAccountId is null, fallback to GL 4230 (backward compat). */
    public function test_charge_reversal_falls_back_to_4230_when_no_account_given(): void
    {
        $this->service->postChargeReversal(
            memberId: 1,
            savingsAccountId: 1,
            amount: 100.00,
            reference: 'REV-TEST-003',
            date: now()->toDateString(),
        );

        $gl = GeneralLedger::where('account_id', $this->maintenanceFees->id)
            ->where('debit', 100.00)
            ->first();

        $this->assertNotNull($gl, 'GL 4230 must be debited as fallback when no account is provided');
    }
}
```

- [ ] **Step 2.2 — Run test to confirm it fails**

```bash
php artisan test --filter=ChargeReversalGlTest
```

Expected: 2 tests fail (the `debitAccountId` parameter does not exist yet), 1 may pass or fail depending on current behavior.

- [ ] **Step 2.3 — Add `?int $debitAccountId = null` to `postChargeReversal()`**

In `app/Tenant/Modules/Accounting/Services/AccountingService.php`, replace the entire `postChargeReversal()` method (lines 174–211):

```php
/**
 * Post a charge reversal journal entry.
 * Mirror of postDepositCharge: DR $debitAccountId (defaults to 4230) / CR Member Savings Liability.
 *
 * @param  int|null  $debitAccountId  The income account that was originally credited.
 *                                    Pass null to fall back to GL 4230 (Account Maintenance Fees).
 */
public function postChargeReversal(
    int $memberId,
    int $savingsAccountId,
    float $amount,
    string $reference,
    string $date,
    string $narration = '',
    ?int $debitAccountId = null,
): JournalEntry {
    $incomeAccount = $debitAccountId ?? $this->getMaintenanceFeesId();

    return $this->createAndPostJournalEntry(
        journalType: 'REVERSAL',
        reference: $reference,
        date: $date,
        narration: $narration ?: "Charge reversal — {$reference}",
        lines: [
            [
                'account_id' => $incomeAccount,
                'debit' => $amount,
                'credit' => 0,
                'narration' => "Fee income reversed — {$reference}",
                'member_id' => $memberId,
                'savings_id' => $savingsAccountId,
                'line_no' => 1,
            ],
            [
                'account_id' => $this->getSavingsLiabilityId(),
                'debit' => 0,
                'credit' => $amount,
                'narration' => "Charge returned to savings — {$reference}",
                'member_id' => $memberId,
                'savings_id' => $savingsAccountId,
                'line_no' => 2,
            ],
        ],
        memberId: $memberId,
        savingsAccountId: $savingsAccountId,
    );
}
```

- [ ] **Step 2.4 — Run test to confirm it passes**

```bash
php artisan test --filter=ChargeReversalGlTest
```

Expected: All 3 tests pass.

- [ ] **Step 2.5 — Commit**

```bash
git add app/Tenant/Modules/Accounting/Services/AccountingService.php \
        tests/Feature/Accounting/ChargeReversalGlTest.php
git commit -m "fix: postChargeReversal accepts debitAccountId to reverse correct income GL"
```

---

## Task 3 — Store `gl_credit_account_id` when posting charges (Bug #1 callers)

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php:715`
- Modify: `app/Tenant/Modules/Members/Services/MemberChargeService.php:83`

- [ ] **Step 3.1 — Update `SavingsAccountController` to store `gl_credit_account_id`**

In `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php`, find the block that creates a charge Transaction (around line 690). The `Transaction::create([...])` call does not currently include `gl_credit_account_id`. Add it:

The `postDepositCharge()` call at line 715 does not pass `creditAccountId`, so it uses the default GL `4230`. We need to capture the resolved ID and write it to the transaction.

Replace the two-step block (Transaction::create + postDepositCharge) with:

```php
// Resolve the income GL that will be credited (defaults to 4230 when no creditAccountId is given)
$maintenanceFeesAccount = \App\Tenant\Modules\Accounting\Models\ChartOfAccount
    ::where('gl_code', '4230')->where('is_active', true)->value('id');

$transaction = Transaction::create([
    'reference' => $ref,
    'member_id' => $savingsAccount->member_id,
    'type' => 'charge',
    'amount' => $validated['amount'],
    'payment_mode' => 'system',
    'deposited_by' => 'System (Manual Charge)',
    'transaction_date' => $validated['charge_date'],
    'account_id' => $savingsAccount->id,
    'account_type' => SavingsAccount::class,
    'narration' => $narration,
    'charge_name' => $validated['charge_name'],
    'gl_credit_account_id' => $maintenanceFeesAccount,
    'is_reversible' => $validated['is_reversible'] ?? true,
    'created_by' => Auth::id(),
    'branch_id' => $branchId,
]);

$this->accounting->postDepositCharge(
    memberId: (int) $savingsAccount->member_id,
    savingsAccountId: (int) $savingsAccount->id,
    chargeAmount: (float) $validated['amount'],
    reference: $ref,
    date: $validated['charge_date'],
    narration: $narration,
);
```

- [ ] **Step 3.2 — Update `MemberChargeService::collectCharge()` to store `gl_credit_account_id`**

In `app/Tenant/Modules/Members/Services/MemberChargeService.php`, `$creditAccountId` is already resolved at line 81. Add it to `Transaction::create()`:

```php
$creditAccountId = $memberCharge->generalCharge?->credit_account_id;

$transaction = Transaction::create([
    'reference' => $ref,
    'member_id' => $account->member_id,
    'type' => 'charge',
    'amount' => $chargeAmount,
    'payment_mode' => 'system',
    'deposited_by' => 'System (Registration Charge)',
    'transaction_date' => now()->toDateString(),
    'account_id' => $account->id,
    'account_type' => SavingsAccount::class,
    'narration' => $narration,
    'charge_name' => $memberCharge->charge_name,
    'gl_credit_account_id' => $creditAccountId,     // ← add this line
    'is_reversible' => (bool) ($memberCharge->generalCharge->is_reversible ?? true),
    'created_by' => Auth::id(),
]);
```

- [ ] **Step 3.3 — Run full test suite to confirm no regressions**

```bash
php artisan test
```

Expected: All tests pass.

- [ ] **Step 3.4 — Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php \
        app/Tenant/Modules/Members/Services/MemberChargeService.php
git commit -m "feat: store gl_credit_account_id on charge transactions for accurate reversals"
```

---

## Task 4 — Pass `gl_credit_account_id` in reversal fallback paths (Bug #1 wiring)

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/TransactionController.php:168`
- Modify: `app/Tenant/Modules/Transactions/Services/ReversalService.php:261`

Both files have identical fallback logic inside `postReversalAccounting()`. Update both.

- [ ] **Step 4.1 — Update `TransactionController::postReversalAccounting()` fallback**

In `app/Tenant/Http/Controllers/Api/V1/TransactionController.php`, find the `postReversalAccounting()` method (line 134). In the `match` expression for `'charge'` (line 168), add `debitAccountId`:

```php
'charge' => $this->accounting->postChargeReversal(
    memberId: $memberId,
    savingsAccountId: $savingsId,
    amount: $amount,
    reference: $reversalRef,
    date: $date,
    narration: $narration,
    debitAccountId: $txn->gl_credit_account_id ? (int) $txn->gl_credit_account_id : null,
),
```

- [ ] **Step 4.2 — Update `ReversalService::postReversalAccounting()` fallback**

In `app/Tenant/Modules/Transactions/Services/ReversalService.php`, find the `postReversalAccounting()` private method (line 228). In the `match` expression for `'charge'` (line 261), add `debitAccountId`:

```php
'charge' => $this->accounting->postChargeReversal(
    memberId: $memberId,
    savingsAccountId: $savingsId,
    amount: $amount,
    reference: $reversalRef,
    date: $date,
    narration: $narration,
    debitAccountId: $txn->gl_credit_account_id ? (int) $txn->gl_credit_account_id : null,
),
```

- [ ] **Step 4.3 — Run test to confirm Bug #1 is fully closed**

```bash
php artisan test --filter=ChargeReversalGlTest
```

Expected: All 3 tests pass.

- [ ] **Step 4.4 — Run full suite**

```bash
php artisan test
```

Expected: All tests pass.

- [ ] **Step 4.5 — Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/TransactionController.php \
        app/Tenant/Modules/Transactions/Services/ReversalService.php
git commit -m "fix: pass gl_credit_account_id to charge reversal fallback in both reversal paths"
```

---

## Task 5 — Extract `resolveSavingsLiabilityGlCode()` and fix disbursement CR (Bugs #2 & #3)

**Files:**
- Modify: `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php:707-782`
- Create: `tests/Feature/Accounting/LoanDisbursementGlTest.php`

- [ ] **Step 5.1 — Write the failing tests**

Create `tests/Feature/Accounting/LoanDisbursementGlTest.php`:

```php
<?php

namespace Tests\Feature\Accounting;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Loans\Services\LoanDisbursementService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use Tests\TenantTestCase;
use Illuminate\Support\Facades\DB;

class LoanDisbursementGlTest extends TenantTestCase
{
    /**
     * Verify that resolveSavingsLiabilityGlCode returns the correct GL code
     * based on the savings product type.
     *
     * We test the private method indirectly via a public accessor exposed only
     * in the test environment using a TestDouble subclass.
     */
    private function makeService(): LoanDisbursementServiceTestDouble
    {
        return app(LoanDisbursementServiceTestDouble::class);
    }

    public function test_mandatory_savings_product_resolves_to_gl_2111(): void
    {
        $product = SavingsProduct::factory()->create(['type' => 'mandatory']);
        $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
        $account->setRelation('savingsProduct', $product);

        $code = $this->makeService()->publicResolveSavingsLiabilityGlCode($account);

        $this->assertSame('2111', $code);
    }

    public function test_voluntary_savings_product_resolves_to_gl_2112(): void
    {
        $product = SavingsProduct::factory()->create(['type' => 'voluntary']);
        $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
        $account->setRelation('savingsProduct', $product);

        $code = $this->makeService()->publicResolveSavingsLiabilityGlCode($account);

        $this->assertSame('2112', $code);
    }

    public function test_fixed_deposit_product_resolves_to_gl_2113(): void
    {
        $product = SavingsProduct::factory()->create(['type' => 'fixed_deposit']);
        $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
        $account->setRelation('savingsProduct', $product);

        $code = $this->makeService()->publicResolveSavingsLiabilityGlCode($account);

        $this->assertSame('2113', $code);
    }

    public function test_unknown_product_type_falls_back_to_gl_2112(): void
    {
        $product = SavingsProduct::factory()->create(['type' => 'other']);
        $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
        $account->setRelation('savingsProduct', $product);

        $code = $this->makeService()->publicResolveSavingsLiabilityGlCode($account);

        $this->assertSame('2112', $code);
    }
}

/**
 * Test double that exposes the private helper for unit testing.
 */
class LoanDisbursementServiceTestDouble extends LoanDisbursementService
{
    public function publicResolveSavingsLiabilityGlCode(SavingsAccount $savings): string
    {
        return $this->resolveSavingsLiabilityGlCode($savings);
    }
}
```

- [ ] **Step 5.2 — Run tests to confirm they fail**

```bash
php artisan test --filter=LoanDisbursementGlTest
```

Expected: Tests fail with "Call to undefined method `resolveSavingsLiabilityGlCode`" (the method does not exist yet).

- [ ] **Step 5.3 — Add `resolveSavingsLiabilityGlCode()` to `LoanDisbursementService`**

In `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php`, add the following private method immediately after the `resolveDisbursementCrAccount()` method (around line 717):

```php
/**
 * Return the correct savings liability GL code for a savings account.
 * Mirrors the same logic in SavingsJournalService::resolveSavingsLiabilityAccount().
 *
 *   Mandatory  → 2111
 *   Fixed      → 2113
 *   All others → 2112  (Voluntary, default)
 */
private function resolveSavingsLiabilityGlCode(SavingsAccount $savings): string
{
    $type = strtolower($savings->savingsProduct?->type ?? '');

    return match (true) {
        str_contains($type, 'mandatory') => '2111',
        str_contains($type, 'fixed')     => '2113',
        default                          => '2112',
    };
}
```

- [ ] **Step 5.4 — Run tests to confirm they pass**

```bash
php artisan test --filter=LoanDisbursementGlTest
```

Expected: All 4 tests pass.

- [ ] **Step 5.5 — Fix Bug #2: `resolveDisbursementCrAccount()` to use correct GL**

In `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php`, replace the entire `resolveDisbursementCrAccount()` method:

```php
/**
 * Resolve the GL account to CR for the disbursed amount.
 *
 * savings_account channel → correct Member Savings Liability GL (2111/2112/2113)
 *                           resolved from the target savings account's product type.
 * All other channels      → product->disbursement_account_id (e.g. Cash at Bank – Loan Disbursement)
 */
private function resolveDisbursementCrAccount(LoanProduct $product, array $data, Loan $loan): int
{
    if (($data['disbursement_method'] ?? '') === 'savings_account'
        && ! empty($data['savings_account_id'])) {

        $savings = SavingsAccount::with('savingsProduct')
            ->find((int) $data['savings_account_id']);

        if ($savings) {
            $glCode = $this->resolveSavingsLiabilityGlCode($savings);
            $accountId = ChartOfAccount::on('tenant')
                ->where('gl_code', $glCode)
                ->where('is_active', true)
                ->value('id');

            if ($accountId) {
                return (int) $accountId;
            }
        }
    }

    return (int) $product->disbursement_account_id;
}
```

- [ ] **Step 5.6 — Fix Bug #3: `postSavingsChargeDeductionJE()` to use correct GL**

In `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php`, replace the entire `postSavingsChargeDeductionJE()` method:

```php
/**
 * JE for debit_savings charge deduction.
 * Debits the correct Member Savings Liability GL based on the loan's savings account product type.
 *
 *   DR  Member Savings Liability (2111 / 2112 / 2113)  = charges
 *   CR  Charges Income                                  = charges
 */
private function postSavingsChargeDeductionJE(Loan $loan, LoanProduct $product, float $chargesTotal, ?int $actorId): void
{
    // Resolve the correct savings liability GL from the loan's linked savings account.
    $savingsGl = null;

    if ($loan->savings_account_id) {
        $savings = SavingsAccount::with('savingsProduct')->find((int) $loan->savings_account_id);

        if ($savings) {
            $glCode    = $this->resolveSavingsLiabilityGlCode($savings);
            $savingsGl = ChartOfAccount::on('tenant')
                ->where('gl_code', $glCode)
                ->where('is_active', true)
                ->first();
        }
    }

    // Fallback: if no savings account is linked, fall back to 2111.
    if (! $savingsGl) {
        $savingsGl = ChartOfAccount::on('tenant')
            ->where('gl_code', '2111')
            ->where('is_active', true)
            ->first();
    }

    if (! $savingsGl) {
        return;
    }

    $narration = "Charge deduction from savings – {$loan->loan_no}";

    $lines = [
        $this->line($savingsGl->id, $chargesTotal, 0.0, $narration, $loan->id),
        $this->line((int) $product->charges_income_account_id, 0.0, $chargesTotal, $narration, $loan->id),
    ];

    $this->postJournalEntry($loan, 'LOAN_CHG_SAV', $narration, $lines, $actorId);
}
```

- [ ] **Step 5.7 — Run the full test suite**

```bash
php artisan test
```

Expected: All tests pass.

- [ ] **Step 5.8 — Run linter**

```bash
composer lint
```

Expected: No linting errors. Fix any style issues reported.

- [ ] **Step 5.9 — Commit**

```bash
git add app/Tenant/Modules/Loans/Services/LoanDisbursementService.php \
        tests/Feature/Accounting/LoanDisbursementGlTest.php
git commit -m "fix: resolve correct savings liability GL (2111/2112/2113) for disbursement and charge deduction"
```

---

## Task 6 — Final integration verification

- [ ] **Step 6.1 — Run complete test suite**

```bash
composer test
```

Expected: Green. Zero failures. Linting passes.

- [ ] **Step 6.2 — Verify DR = CR invariant on all new JEs**

Run this against a tenant database to confirm every posted JE in the system is balanced:

```bash
php artisan tinker --no-interaction <<'EOF'
use App\Tenant\Modules\Accounting\Models\JournalEntry;

$unbalanced = JournalEntry::with('lines')
    ->where('status', 'posted')
    ->get()
    ->filter(function ($je) {
        $dr = $je->lines->sum('debit');
        $cr = $je->lines->sum('credit');
        return abs($dr - $cr) > 0.01;
    });

echo "Unbalanced JEs: " . $unbalanced->count() . "\n";
$unbalanced->each(fn($je) => echo "  {$je->entry_no} — DR={$je->lines->sum('debit')} CR={$je->lines->sum('credit')}\n");
EOF
```

Expected output: `Unbalanced JEs: 0`

- [ ] **Step 6.3 — Tag the fix**

```bash
git tag accounting-critical-fixes-v1
```

---

## Self-Review Checklist

**Spec coverage:**
- [x] Bug #1 — `postChargeReversal` wrong account → Tasks 2, 3, 4
- [x] Bug #2 — Disbursement savings channel always uses 2111 → Task 5 (step 5.5)
- [x] Bug #3 — `debit_savings` charge mode always uses 2111 → Task 5 (step 5.6)
- [x] Tests for each bug → Tasks 2 and 5
- [x] Backward compatibility — existing callers with no `debitAccountId` still work → Task 2, step 2.3 (default `null` → fallback to 4230)

**Placeholder scan:** No TBD, TODO, or vague steps. All code is complete.

**Type consistency:**
- `resolveSavingsLiabilityGlCode(SavingsAccount $savings): string` — defined in step 5.3, used in steps 5.5 and 5.6 ✅
- `postChargeReversal(..., ?int $debitAccountId = null)` — defined in step 2.3, called in steps 4.1 and 4.2 ✅
- `gl_credit_account_id` — added to migration (step 1.1), fillable (step 1.2), stored in steps 3.1 and 3.2, read in steps 4.1 and 4.2 ✅
