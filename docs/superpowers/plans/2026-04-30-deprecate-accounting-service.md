# Deprecate AccountingService Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate the dual savings GL path by adding two missing methods to `SavingsJournalService`, migrating all five `AccountingService` callers, and deleting `AccountingService`.

**Architecture:** Two new public methods (`reverseJournalEntry`, `postChargeReversal`) are added to `SavingsJournalService` following its existing `safe()`/`makeJe()`/`createLine()` pattern. Five consuming files are migrated one-by-one (TDD: tests first, then implementation). `AccountingService.php` is deleted last after confirming zero remaining references.

**Tech Stack:** Laravel 12, PHP 8.3, Pest, `TenantTestCase` for test isolation, `SavingsCoaResolverInterface` for dynamic GL resolution (2111/2112/2113 based on `account_type`).

---

## Context for the Implementer

**Why this matters:** `AccountingService` hardcodes GL 2112 (Voluntary Savings) for all savings transactions. `SavingsJournalService` uses `SavingsCoaResolver` to pick the right GL dynamically — 2111 for mandatory, 2112 for voluntary, 2113 for fixed. Both services are currently active, so mandatory savings charges and reversals post to the wrong GL.

**Key files to understand before starting:**
- `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` — the canonical service. Study `postDeposit()`, `createLine()`, `makeJe()`, `safe()`, and `postReversalFromJe()` before writing any code.
- `app/Tenant/Modules/Accounting/Services/AccountingService.php` — the file you're replacing. Read `reverseJournalEntry()` and `postChargeReversal()` to understand what the new methods must replicate.
- `tests/Feature/Accounting/SavingsReversalGlTest.php` — these tests currently **fail** (they expect GL 2111 but get 2112). They should **pass** after this migration.
- `tests/Feature/Accounting/ChargeReversalGlTest.php` — these tests use `AccountingService` directly. They must be updated to use `SavingsJournalService` before `AccountingService` is deleted.

**Test setup pattern** (copy this in every new test):
```php
use Tests\TenantTestCase;

class MyTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Create ChartOfAccount rows needed, then Member, SavingsProduct, SavingsAccount
    }
}
```
`TenantTestCase` wraps each test in a DB transaction that rolls back — no cleanup needed.

**GL codes used in tests:**
- `2111` — Mandatory Savings Liability (CR normal)
- `2112` — Voluntary Savings Liability (CR normal)
- `4230` — Account Maintenance Fees / income (CR normal)
- `1112` — Cash at Bank (DR normal)

---

## File Map

| File | Action |
|------|--------|
| `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` | Add 2 public methods |
| `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php` | Migrate + remove injection |
| `app/Tenant/Modules/Members/Services/MemberChargeService.php` | Change param types |
| `app/Tenant/Http/Controllers/Api/V1/TransactionController.php` | Migrate + remove injection |
| `app/Tenant/Modules/Transactions/Services/ReversalService.php` | Migrate + remove injection |
| `app/Tenant/Http/Controllers/Api/V1/MemberController.php` | Remove dead injection |
| `app/Tenant/Http/Controllers/Api/V1/MemberChargeController.php` | Remove dead injection |
| `tests/Feature/Accounting/AccountingServiceMigrationTest.php` | Create |
| `tests/Feature/Accounting/ChargeReversalGlTest.php` | Update to use SavingsJournalService |
| `app/Tenant/Modules/Accounting/Services/AccountingService.php` | Delete (final step) |

---

## Task 1: Add `reverseJournalEntry()` and `postChargeReversal()` to SavingsJournalService

**Files:**
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php`
- Create: `tests/Feature/Accounting/AccountingServiceMigrationTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Accounting/AccountingServiceMigrationTest.php`:

```php
<?php

namespace Tests\Feature\Accounting;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Tests\TenantTestCase;

class AccountingServiceMigrationTest extends TenantTestCase
{
    private ChartOfAccount $gl2111;
    private ChartOfAccount $gl2112;
    private ChartOfAccount $gl4230;
    private ChartOfAccount $gl1112;
    private SavingsProduct $product;
    private Member $member;
    private SavingsAccount $mandatoryAccount;
    private SavingsAccount $voluntaryAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gl1112 = ChartOfAccount::create([
            'gl_code' => '1112', 'name' => 'Cash at Bank', 'account_type' => 'ASSET',
            'account_subtype' => 'bank', 'normal_balance' => 'DR', 'level' => 4,
            'is_control' => false, 'is_postable' => true, 'is_active' => true,
        ]);
        $this->gl2111 = ChartOfAccount::create([
            'gl_code' => '2111', 'name' => 'Mandatory Savings', 'account_type' => 'LIABILITY',
            'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 4,
            'is_control' => false, 'is_postable' => true, 'is_active' => true,
        ]);
        $this->gl2112 = ChartOfAccount::create([
            'gl_code' => '2112', 'name' => 'Voluntary Savings', 'account_type' => 'LIABILITY',
            'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 4,
            'is_control' => false, 'is_postable' => true, 'is_active' => true,
        ]);
        $this->gl4230 = ChartOfAccount::create([
            'gl_code' => '4230', 'name' => 'Account Maintenance Fees', 'account_type' => 'INCOME',
            'account_subtype' => 'fee_income', 'normal_balance' => 'CR', 'level' => 4,
            'is_control' => false, 'is_postable' => true, 'is_active' => true,
        ]);

        $this->product = SavingsProduct::create([
            'code' => 'MAN', 'name' => 'Mandatory', 'type' => 'standard', 'status' => 'active',
        ]);

        $this->member = Member::create([
            'name' => 'Test Member', 'member_number' => 'M-MIG-001',
            'code' => 'MMIG001', 'status' => 'active', 'password' => bcrypt('password'),
        ]);

        $this->mandatoryAccount = SavingsAccount::create([
            'savings_product_id' => $this->product->id,
            'member_id' => $this->member->id,
            'account_no' => 'MAN-MIG-001',
            'account_type' => 'mandatory',
            'balance' => 1000,
            'status' => 'active',
            'code' => 'MANMIG001',
        ]);

        $this->voluntaryAccount = SavingsAccount::create([
            'savings_product_id' => $this->product->id,
            'member_id' => $this->member->id,
            'account_no' => 'VOL-MIG-001',
            'account_type' => 'voluntary',
            'balance' => 1000,
            'status' => 'active',
            'code' => 'VOLMIG001',
        ]);
    }

    public function test_postChargeReversal_debits_income_gl_and_credits_mandatory_liability(): void
    {
        $reversal = Transaction::create([
            'reference' => 'CHG-REV-001',
            'member_id' => $this->member->id,
            'type' => 'reversal',
            'amount' => 150,
            'payment_mode' => 'system',
            'deposited_by' => 'System',
            'transaction_date' => now()->toDateString(),
            'account_id' => $this->mandatoryAccount->id,
            'account_type' => SavingsAccount::class,
            'gl_credit_account_id' => $this->gl4230->id,
            'created_by' => 1,
        ]);

        $je = app(SavingsJournalService::class)
            ->postChargeReversal($reversal, $this->mandatoryAccount);

        $this->assertNotNull($je);

        // DR income GL (4230)
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $je->id,
            'account_id' => $this->gl4230->id,
            'debit' => 150,
            'credit' => 0,
        ]);

        // CR savings liability GL 2111 (mandatory — NOT 2112)
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $je->id,
            'account_id' => $this->gl2111->id,
            'debit' => 0,
            'credit' => 150,
        ]);

        // Must NOT use 2112
        $this->assertDatabaseMissing('journal_entry_lines', [
            'journal_entry_id' => $je->id,
            'account_id' => $this->gl2112->id,
        ]);
    }

    public function test_postChargeReversal_falls_back_to_gl4230_when_no_gl_credit_account_id(): void
    {
        $reversal = Transaction::create([
            'reference' => 'CHG-REV-002',
            'member_id' => $this->member->id,
            'type' => 'reversal',
            'amount' => 50,
            'payment_mode' => 'system',
            'deposited_by' => 'System',
            'transaction_date' => now()->toDateString(),
            'account_id' => $this->voluntaryAccount->id,
            'account_type' => SavingsAccount::class,
            'gl_credit_account_id' => null, // no original income GL stored
            'created_by' => 1,
        ]);

        $je = app(SavingsJournalService::class)
            ->postChargeReversal($reversal, $this->voluntaryAccount);

        $this->assertNotNull($je);

        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $je->id,
            'account_id' => $this->gl4230->id,
            'debit' => 50,
        ]);
    }

    public function test_reverseJournalEntry_mirrors_lines_and_marks_original_reversed(): void
    {
        $service = app(SavingsJournalService::class);

        // Post an original deposit JE using an existing method
        $depositTxn = Transaction::create([
            'reference' => 'DEP-REV-001',
            'member_id' => $this->member->id,
            'type' => 'deposit',
            'amount' => 300,
            'payment_mode' => 'cash',
            'deposited_by' => 'Teller',
            'transaction_date' => now()->toDateString(),
            'account_id' => $this->mandatoryAccount->id,
            'account_type' => SavingsAccount::class,
            'created_by' => 1,
        ]);

        $originalJe = $service->postDeposit($depositTxn, $this->mandatoryAccount);
        $this->assertNotNull($originalJe);

        // Now reverse it
        $reversalJe = $service->reverseJournalEntry(
            original: $originalJe,
            reversalReference: 'DEP-REV-REV-001',
            date: now()->toDateString(),
            reversedBy: 1,
            narration: 'Test reversal',
        );

        $this->assertNotNull($reversalJe);

        // Original should be marked reversed
        $this->assertDatabaseHas('journal_entries', [
            'id' => $originalJe->id,
            'status' => 'reversed',
        ]);

        // Reversal JE must have swapped DR/CR from original
        $originalLines = $originalJe->load('lines')->lines;
        $reversalLines = $reversalJe->load('lines')->lines->keyBy('account_id');

        foreach ($originalLines as $origLine) {
            $mirror = $reversalLines[$origLine->account_id];
            $this->assertEquals((float) $origLine->debit, (float) $mirror->credit);
            $this->assertEquals((float) $origLine->credit, (float) $mirror->debit);
        }
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
php artisan test tests/Feature/Accounting/AccountingServiceMigrationTest.php --no-coverage
```

Expected: 3 failures — `Call to undefined method SavingsJournalService::postChargeReversal()` and `::reverseJournalEntry()`.

- [ ] **Step 3: Add `postChargeReversal()` to SavingsJournalService**

Open `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php`.

Add these `use` statements at the top if not already present (they are already imported):
- `use App\Tenant\Modules\Transactions\Models\Transaction;` ✓ already present
- `use App\Models\Member;` ✓ already present

Add the following method **after `postReversal()` and before the `// ── Core posting engine` comment**:

```php
/**
 * DR original income GL (stored on reversal transaction)  CR Member Savings Liability
 * Used when reversing a charge transaction and the original JE cannot be found.
 */
public function postChargeReversal(
    Transaction $reversal,
    SavingsAccount $account,
): ?JournalEntry {
    return $this->safe(function () use ($reversal, $account) {
        $account->loadMissing('savingsProduct');
        $date = now()->toDateString();

        $debitAccount = $reversal->gl_credit_account_id
            ? ChartOfAccount::on('tenant')->find($reversal->gl_credit_account_id)
            : null;
        $debitAccount ??= $this->coa->resolveByGlCode('4230');

        $creditAccount = $this->coa->resolveSavingsLiabilityAccount($account);
        $narration = "Charge reversal – {$account->account_no} ({$reversal->reference})";

        $je = $this->makeJe($date, $reversal->reference, $narration, 'SAVINGS_REVERSAL', $reversal->created_by);

        $this->createLine($je, $debitAccount, (float) $reversal->amount, 0.0, $narration, 1, $date, $account->member_id, $account->id);
        if ($account->member_id) {
            $this->gl->postToSubLedger(
                $je->id, $debitAccount->id, $account->member_id, Member::class,
                (float) $reversal->amount, 0.0, $date, $narration, $debitAccount->normal_balance ?? 'CR',
            );
        }

        $this->createLine($je, $creditAccount, 0.0, (float) $reversal->amount, $narration, 2, $date, $account->member_id, $account->id);
        if ($account->member_id) {
            $this->gl->postToSubLedger(
                $je->id, $creditAccount->id, $account->member_id, Member::class,
                0.0, (float) $reversal->amount, $date, $narration, $creditAccount->normal_balance ?? 'CR',
            );
        }

        return $je;
    });
}
```

- [ ] **Step 4: Add `reverseJournalEntry()` to SavingsJournalService**

Add the following method **directly after `postChargeReversal()`**:

```php
/**
 * Mirror a posted JournalEntry: swap all DR/CR lines and mark the original as reversed.
 * Used by TransactionController and ReversalService when the original JE is found.
 */
public function reverseJournalEntry(
    JournalEntry $original,
    string $reversalReference,
    string $date,
    ?int $reversedBy = null,
    string $narration = '',
): ?JournalEntry {
    return $this->safe(function () use ($original, $reversalReference, $date, $reversedBy, $narration) {
        $original->load('lines.account');
        $note = $narration ?: "Reversal of JE#{$original->entry_no} – {$original->reference}";
        $je = $this->makeJe($date, $reversalReference, $note, 'SAVINGS_REVERSAL', $reversedBy);

        $lineNo = 1;
        foreach ($original->lines as $orig) {
            $jel = $this->createLine(
                $je, $orig->account,
                (float) $orig->credit, (float) $orig->debit, // swap DR/CR
                $note, $lineNo++, $date,
                $orig->member_id, $orig->savings_id,
            );

            if ($orig->member_id) {
                $this->gl->postToSubLedger(
                    $je->id, $jel->account_id,
                    $orig->member_id, Member::class,
                    (float) $jel->debit, (float) $jel->credit,
                    $date, $note, $orig->account->normal_balance ?? 'DR',
                );
            }
        }

        $original->update([
            'status' => 'reversed',
            'reversed_at' => now(),
            'reversed_by' => $reversedBy,
        ]);

        return $je;
    });
}
```

- [ ] **Step 5: Run tests to confirm they pass**

```bash
php artisan test tests/Feature/Accounting/AccountingServiceMigrationTest.php --no-coverage
```

Expected: 3 tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Tenant/Modules/Accounting/Services/SavingsJournalService.php \
        tests/Feature/Accounting/AccountingServiceMigrationTest.php
git commit -m "feat: add reverseJournalEntry and postChargeReversal to SavingsJournalService"
```

---

## Task 2: Migrate SavingsAccountController

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php`

- [ ] **Step 1: Remove `AccountingService` injection from constructor**

Current constructor (lines ~24–29):
```php
public function __construct(
    protected SavingsAccountService $service,
    protected AccountingService $accounting,
    protected SavingsJournalService $savingsJournal,
) {}
```

Replace with:
```php
public function __construct(
    protected SavingsAccountService $service,
    protected SavingsJournalService $savingsJournal,
) {}
```

Remove the `use` import:
```php
use App\Tenant\Modules\Accounting\Services\AccountingService;
```

- [ ] **Step 2: Replace `accounting->postDepositCharge()` in the `charge()` method**

Find the block inside `DB::connection('tenant')->transaction(function () use ...)` in the `charge()` method. It looks like this:

```php
Transaction::create([
    'reference' => $ref,
    // ...
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

Replace with (capture the return value of `Transaction::create`):
```php
$chargeTxn = Transaction::create([
    'reference' => $ref,
    // ...
    'created_by' => Auth::id(),
    'branch_id' => $branchId,
]);

$this->savingsJournal->postCharge($chargeTxn, $savingsAccount);
```

- [ ] **Step 3: Replace `accounting` argument in deposit()'s `collectPendingCharges` call**

Find (around line 429):
```php
app(MemberChargeService::class)->collectPendingCharges(
    $member,
    $savingsAccount,
    $this->accounting,
);
```

Replace with:
```php
app(MemberChargeService::class)->collectPendingCharges(
    $member,
    $savingsAccount,
    $this->savingsJournal,
);
```

- [ ] **Step 4: Run the full accounting test suite**

```bash
php artisan test tests/Feature/Accounting/ --no-coverage
```

Expected: all tests pass (no regressions).

- [ ] **Step 5: Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php
git commit -m "refactor: migrate SavingsAccountController from AccountingService to SavingsJournalService"
```

---

## Task 3: Migrate MemberChargeService

**Files:**
- Modify: `app/Tenant/Modules/Members/Services/MemberChargeService.php`

- [ ] **Step 1: Update `use` import**

Replace:
```php
use App\Tenant\Modules\Accounting\Services\AccountingService;
```
With:
```php
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
```

- [ ] **Step 2: Update `collectPendingCharges()` signature**

Replace:
```php
public function collectPendingCharges(
    Member $member,
    SavingsAccount $account,
    AccountingService $accounting
): void {
```
With:
```php
public function collectPendingCharges(
    Member $member,
    SavingsAccount $account,
    SavingsJournalService $savingsJournal
): void {
```

Update the body to pass `$savingsJournal` to `collectCharge()`:
```php
foreach ($pending as $memberCharge) {
    $this->collectCharge($memberCharge, $account, $savingsJournal);
}
```

- [ ] **Step 3: Update `collectCharge()` signature and GL call**

Replace:
```php
public function collectCharge(
    MemberCharge $memberCharge,
    SavingsAccount $account,
    AccountingService $accounting
): bool {
```
With:
```php
public function collectCharge(
    MemberCharge $memberCharge,
    SavingsAccount $account,
    SavingsJournalService $savingsJournal
): bool {
```

Find this block in `collectCharge()` (the GL call after `Transaction::create`):
```php
$accounting->postDepositCharge(
    memberId: (int) $account->member_id,
    savingsAccountId: (int) $account->id,
    chargeAmount: $chargeAmount,
    reference: $ref,
    date: now()->toDateString(),
    narration: $narration,
    creditAccountId: $creditAccountId,
);
```

Replace with (`$transaction` is the variable returned by `Transaction::create(...)` on the line above):
```php
$savingsJournal->postCharge($transaction, $account);
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Feature/Accounting/ --no-coverage
```

Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add app/Tenant/Modules/Members/Services/MemberChargeService.php
git commit -m "refactor: migrate MemberChargeService from AccountingService to SavingsJournalService"
```

---

## Task 4: Migrate TransactionController

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/TransactionController.php`

- [ ] **Step 1: Remove `AccountingService` injection**

Current constructor:
```php
public function __construct(
    protected AccountingService $accounting,
    protected SavingsJournalService $savingsJournal,
) {}
```

Replace with:
```php
public function __construct(
    protected SavingsJournalService $savingsJournal,
) {}
```

Remove import:
```php
use App\Tenant\Modules\Accounting\Services\AccountingService;
```

- [ ] **Step 2: Replace all `accounting->*` calls in `postReversalAccounting()`**

Current `postReversalAccounting()` private method:
```php
private function postReversalAccounting(Transaction $txn, string $reversalRef): void
{
    $originalJe = JournalEntry::where('reference', $txn->reference)
        ->where('status', 'posted')
        ->first();

    if ($originalJe) {
        $this->accounting->reverseJournalEntry(
            original: $originalJe,
            reversalReference: $reversalRef,
            date: now()->toDateString(),
            reversedBy: auth()->id(),
            narration: "Reversal of {$txn->type} txno:{$txn->reference}",
        );

        return;
    }

    // Fallback: no original JE found, synthesise correcting entry
    $memberId = (int) $txn->member_id;
    $savingsId = (int) $txn->account_id;
    $amount = (float) $txn->amount;
    $date = now()->toDateString();
    $narration = "Reversal of {$txn->type} — {$txn->reference}";

    $savingsAccount = SavingsAccount::find($txn->account_id);
    $reversalTxn = Transaction::where('reference', $reversalRef)->first();

    match ($txn->type) {
        'deposit' => ($savingsAccount && $reversalTxn)
            ? $this->savingsJournal->postWithdrawal($reversalTxn, $savingsAccount)
            : $this->accounting->postWithdrawal(
                memberId: $memberId, savingsAccountId: $savingsId,
                amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
            ),
        'withdrawal' => ($savingsAccount && $reversalTxn)
            ? $this->savingsJournal->postDeposit($reversalTxn, $savingsAccount)
            : $this->accounting->postDeposit(
                memberId: $memberId, savingsAccountId: $savingsId,
                amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
            ),
        'charge' => $this->accounting->postChargeReversal(
            memberId: $memberId, savingsAccountId: $savingsId,
            amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
            debitAccountId: $txn->gl_credit_account_id,
        ),
        default => null,
    };
}
```

Replace the entire method with:
```php
private function postReversalAccounting(Transaction $txn, string $reversalRef): void
{
    $originalJe = JournalEntry::where('reference', $txn->reference)
        ->where('status', 'posted')
        ->first();

    if ($originalJe) {
        $this->savingsJournal->reverseJournalEntry(
            original: $originalJe,
            reversalReference: $reversalRef,
            date: now()->toDateString(),
            reversedBy: auth()->id(),
            narration: "Reversal of {$txn->type} txno:{$txn->reference}",
        );

        return;
    }

    // Fallback: original JE not found — synthesise a correcting entry.
    // $reversalTxn was just created above this call so should always exist.
    $savingsAccount = SavingsAccount::find($txn->account_id);
    $reversalTxn = Transaction::where('reference', $reversalRef)->first();

    if (! $savingsAccount || ! $reversalTxn) {
        \Illuminate\Support\Facades\Log::warning("postReversalAccounting: missing account or reversal transaction for ref {$reversalRef}");

        return;
    }

    match ($txn->type) {
        'deposit'    => $this->savingsJournal->postWithdrawal($reversalTxn, $savingsAccount),
        'withdrawal' => $this->savingsJournal->postDeposit($reversalTxn, $savingsAccount),
        'charge'     => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
        default      => null,
    };
}
```

Also add `Log` import if not already present:
```php
use Illuminate\Support\Facades\Log;
```

- [ ] **Step 3: Run tests**

```bash
php artisan test tests/Feature/Accounting/ --no-coverage
```

Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/TransactionController.php
git commit -m "refactor: migrate TransactionController from AccountingService to SavingsJournalService"
```

---

## Task 5: Migrate ReversalService

**Files:**
- Modify: `app/Tenant/Modules/Transactions/Services/ReversalService.php`

- [ ] **Step 1: Remove `AccountingService` injection**

Current constructor:
```php
public function __construct(
    protected AccountingService $accounting,
    protected SavingsJournalService $savingsJournal,
) {}
```

Replace with:
```php
public function __construct(
    protected SavingsJournalService $savingsJournal,
) {}
```

Remove import:
```php
use App\Tenant\Modules\Accounting\Services\AccountingService;
```

- [ ] **Step 2: Replace all `accounting->*` calls in `postReversalAccounting()`**

The `postReversalAccounting()` in `ReversalService` is identical in structure to `TransactionController`. Apply the same replacement:

Replace the entire `postReversalAccounting()` method body with:
```php
private function postReversalAccounting(Transaction $txn, string $reversalRef): void
{
    $originalJe = JournalEntry::where('reference', $txn->reference)
        ->where('status', 'posted')
        ->first();

    if ($originalJe) {
        $this->savingsJournal->reverseJournalEntry(
            original: $originalJe,
            reversalReference: $reversalRef,
            date: now()->toDateString(),
            reversedBy: auth()->id(),
            narration: "Reversal of {$txn->type} txno:{$txn->reference}",
        );

        return;
    }

    $savingsAccount = SavingsAccount::find($txn->account_id);
    $reversalTxn = Transaction::where('reference', $reversalRef)->first();

    if (! $savingsAccount || ! $reversalTxn) {
        \Illuminate\Support\Facades\Log::warning("postReversalAccounting: missing account or reversal transaction for ref {$reversalRef}");

        return;
    }

    match ($txn->type) {
        'deposit'    => $this->savingsJournal->postWithdrawal($reversalTxn, $savingsAccount),
        'withdrawal' => $this->savingsJournal->postDeposit($reversalTxn, $savingsAccount),
        'charge'     => $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount),
        default      => null,
    };
}
```

- [ ] **Step 3: Run the full accounting test suite — including `SavingsReversalGlTest`**

```bash
php artisan test tests/Feature/Accounting/ --no-coverage
```

Expected: **all pass**, including the two `SavingsReversalGlTest` tests that previously failed (they expect GL 2111 for mandatory accounts — they now pass because `ReversalService` routes through `SavingsJournalService` which resolves 2111 correctly).

- [ ] **Step 4: Commit**

```bash
git add app/Tenant/Modules/Transactions/Services/ReversalService.php
git commit -m "refactor: migrate ReversalService from AccountingService to SavingsJournalService"
```

---

## Task 6: Remove Dead Injections, Update ChargeReversalGlTest, Delete AccountingService

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/MemberController.php`
- Modify: `app/Tenant/Http/Controllers/Api/V1/MemberChargeController.php`
- Modify: `tests/Feature/Accounting/ChargeReversalGlTest.php`
- Delete: `app/Tenant/Modules/Accounting/Services/AccountingService.php`

- [ ] **Step 1: Remove dead injection from MemberController**

In `app/Tenant/Http/Controllers/Api/V1/MemberController.php`:

Remove the `use` import:
```php
use App\Tenant\Modules\Accounting\Services\AccountingService;
```

Remove `protected AccountingService $accounting,` from the constructor. The constructor becomes:
```php
public function __construct(
    protected SavingsAccountService $savingsAccountService,
    protected MemberChargeService $memberChargeService,
    protected ShareAccountingServiceInterface $shareAccountingService,
) {}
```

- [ ] **Step 2: Remove dead injection from MemberChargeController**

In `app/Tenant/Http/Controllers/Api/V1/MemberChargeController.php`:

Remove:
```php
use App\Tenant\Modules\Accounting\Services\AccountingService;
```

Remove `protected AccountingService $accounting,` from the constructor. The constructor becomes:
```php
public function __construct(
    protected MemberChargeService $memberChargeService,
) {}
```

- [ ] **Step 3: Update ChargeReversalGlTest to use SavingsJournalService**

`tests/Feature/Accounting/ChargeReversalGlTest.php` currently tests `AccountingService` directly. Replace its entire content:

```php
<?php

namespace Tests\Feature\Accounting;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Tests\TenantTestCase;

class ChargeReversalGlTest extends TenantTestCase
{
    private ChartOfAccount $maintenanceFees;
    private ChartOfAccount $applicationFees;
    private ChartOfAccount $savingsLiability;
    private SavingsAccount $account;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->savingsLiability = ChartOfAccount::create([
            'gl_code' => '2112', 'name' => 'Voluntary Savings Deposits',
            'account_type' => 'LIABILITY', 'account_subtype' => 'current_liability',
            'normal_balance' => 'CR', 'level' => 2,
            'is_control' => false, 'is_postable' => true, 'is_active' => true, 'allow_manual' => false, 'sort_order' => 10,
        ]);
        $this->maintenanceFees = ChartOfAccount::create([
            'gl_code' => '4230', 'name' => 'Account Maintenance Fees',
            'account_type' => 'INCOME', 'account_subtype' => 'fee_income',
            'normal_balance' => 'CR', 'level' => 2,
            'is_control' => false, 'is_postable' => true, 'is_active' => true, 'allow_manual' => false, 'sort_order' => 20,
        ]);
        $this->applicationFees = ChartOfAccount::create([
            'gl_code' => '4210', 'name' => 'Application Fees',
            'account_type' => 'INCOME', 'account_subtype' => 'fee_income',
            'normal_balance' => 'CR', 'level' => 2,
            'is_control' => false, 'is_postable' => true, 'is_active' => true, 'allow_manual' => false, 'sort_order' => 15,
        ]);

        $product = SavingsProduct::create([
            'code' => 'VOL', 'name' => 'Voluntary', 'type' => 'standard', 'status' => 'active',
        ]);
        $this->member = Member::create([
            'name' => 'Test', 'member_number' => 'M-CRG-001',
            'code' => 'MCRG001', 'status' => 'active', 'password' => bcrypt('x'),
        ]);
        $this->account = SavingsAccount::create([
            'savings_product_id' => $product->id, 'member_id' => $this->member->id,
            'account_no' => 'VOL-CRG-001', 'account_type' => 'voluntary',
            'balance' => 1000, 'status' => 'active', 'code' => 'VOLCRG001',
        ]);
    }

    private function makeReversalTxn(string $ref, ?int $glCreditAccountId, float $amount): Transaction
    {
        return Transaction::create([
            'reference' => $ref,
            'member_id' => $this->member->id,
            'type' => 'reversal',
            'amount' => $amount,
            'payment_mode' => 'system',
            'deposited_by' => 'System',
            'transaction_date' => now()->toDateString(),
            'account_id' => $this->account->id,
            'account_type' => SavingsAccount::class,
            'gl_credit_account_id' => $glCreditAccountId,
            'created_by' => 1,
        ]);
    }

    public function test_charge_reversal_debits_original_income_account_4230(): void
    {
        $txn = $this->makeReversalTxn('REV-001', $this->maintenanceFees->id, 500);

        app(SavingsJournalService::class)->postChargeReversal($txn, $this->account);

        $this->assertDatabaseHas('general_ledger', [
            'account_id' => $this->maintenanceFees->id,
            'debit' => 500,
        ]);
    }

    public function test_charge_reversal_debits_custom_income_account_not_4230(): void
    {
        $txn = $this->makeReversalTxn('REV-002', $this->applicationFees->id, 250);

        app(SavingsJournalService::class)->postChargeReversal($txn, $this->account);

        $this->assertDatabaseHas('general_ledger', [
            'account_id' => $this->applicationFees->id,
            'debit' => 250,
        ]);
        $this->assertDatabaseMissing('general_ledger', [
            'account_id' => $this->maintenanceFees->id,
            'debit' => 250,
        ]);
    }

    public function test_charge_reversal_falls_back_to_4230_when_no_account_given(): void
    {
        $txn = $this->makeReversalTxn('REV-003', null, 100);

        app(SavingsJournalService::class)->postChargeReversal($txn, $this->account);

        $this->assertDatabaseHas('general_ledger', [
            'account_id' => $this->maintenanceFees->id,
            'debit' => 100,
        ]);
    }
}
```

- [ ] **Step 4: Run full test suite to confirm everything passes**

```bash
php artisan test tests/Feature/Accounting/ --no-coverage
```

Expected: all pass. If any fail, fix before continuing.

- [ ] **Step 5: Confirm zero remaining references to AccountingService**

```bash
grep -r "AccountingService" app/ --include="*.php"
```

Expected output: **empty** (zero lines). If any appear, fix them before deleting the file.

- [ ] **Step 6: Delete AccountingService**

```bash
rm app/Tenant/Modules/Accounting/Services/AccountingService.php
```

- [ ] **Step 7: Run the full test suite one final time**

```bash
php artisan test --no-coverage
```

Expected: all tests pass with no `AccountingService` references.

- [ ] **Step 8: Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/MemberController.php \
        app/Tenant/Http/Controllers/Api/V1/MemberChargeController.php \
        tests/Feature/Accounting/ChargeReversalGlTest.php
git rm app/Tenant/Modules/Accounting/Services/AccountingService.php
git commit -m "refactor: delete AccountingService and remove all dead injections — dual GL path eliminated"
```
