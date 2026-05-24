# Deprecate AccountingService — Pure Migration to SavingsJournalService

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate the dual savings GL path by migrating all `AccountingService` callers to `SavingsJournalService` and deleting `AccountingService`.

**Architecture:** Two new methods are added to `SavingsJournalService` to cover the two call patterns that have no existing equivalent. All five consuming files are then migrated one-by-one. `AccountingService` is deleted as the final step, with a test confirming the container can no longer resolve it.

**Tech Stack:** Laravel 12, PHP 8.3, Pest, tenant DB connection (`tenant`), `SavingsCoaResolverInterface` for dynamic GL resolution.

---

## Problem

`AccountingService` always posts savings transactions to GL 2112 (Voluntary Savings — hardcoded). `SavingsJournalService` resolves the correct liability GL dynamically (2111/2112/2113) based on the savings account product type. Both services are active, so mandatory savings transactions posted via `AccountingService` are incorrectly credited to the voluntary savings GL, making the trial balance unreliable by product type.

## Files

### New / Modified
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` — add `reverseJournalEntry()` and `postChargeReversal()`
- Modify: `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php` — replace `accounting->postDepositCharge()`, change `MemberChargeService` call, remove `AccountingService` injection
- Modify: `app/Tenant/Modules/Members/Services/MemberChargeService.php` — change parameter type to `SavingsJournalService`
- Modify: `app/Tenant/Http/Controllers/Api/V1/TransactionController.php` — replace all `accounting->*` calls, remove injection
- Modify: `app/Tenant/Modules/Transactions/Services/ReversalService.php` — replace all `accounting->*` calls, remove injection
- Modify: `app/Tenant/Http/Controllers/Api/V1/MemberController.php` — remove unused `AccountingService` injection
- Modify: `app/Tenant/Http/Controllers/Api/V1/MemberChargeController.php` — remove unused `AccountingService` injection
- Delete: `app/Tenant/Modules/Accounting/Services/AccountingService.php`

### Tests
- Create: `tests/Feature/Accounting/AccountingServiceMigrationTest.php`

---

## New Methods on SavingsJournalService

### `reverseJournalEntry()`

Signature:
```php
public function reverseJournalEntry(
    JournalEntry $original,
    string $reversalReference,
    string $date,
    ?int $reversedBy = null,
    string $narration = '',
): ?JournalEntry
```

Implementation: wrap `safe()` around a call to the existing private `postReversalFromJe($original, $reversalTxn)`. Because `postReversalFromJe` requires a `Transaction` object but this method only has raw params, promote `postReversalFromJe` to accept `JournalEntry` directly and handle the GL mirror + `status = reversed` update internally.

Actually — simpler: inline the mirror logic directly, identical to `AccountingService::reverseJournalEntry`:
1. Load `$original->lines`
2. Build mirror lines (swap debit/credit)
3. Call `makeJe($date, $reversalReference, $narration ?: "Reversal of JE#{$original->entry_no}", 'SAVINGS_REVERSAL', $reversedBy)`
4. For each mirror line call `createLine(...)` + `gl->postToGeneralLedger(...)` + `gl->postToSubLedger(...)` (if member_id present)
5. Update original: `status = reversed`, `reversed_by`, `reversed_at = now()`
6. Return the new JE

Wrapped in `safe()`.

### `postChargeReversal()`

Signature:
```php
public function postChargeReversal(
    Transaction $reversal,
    SavingsAccount $account,
): ?JournalEntry
```

Implementation:
1. Resolve debit account: `ChartOfAccount::on('tenant')->find($reversal->gl_credit_account_id)` — fall back to `$this->coa->resolveByGlCode('4230')` if null
2. Resolve credit account: `$this->coa->resolveSavingsLiabilityAccount($account)`
3. Build narration: `"Charge reversal – {$account->account_no} ({$reversal->reference})"`
4. `$je = $this->makeJe($date, $reversal->reference, $narration, 'SAVINGS_REVERSAL', $reversal->created_by)`
5. `createLine($je, $debitAccount, $reversal->amount, 0.0, ...)` + GL + SubLedger
6. `createLine($je, $creditAccount, 0.0, $reversal->amount, ...)` + GL + SubLedger
7. Wrapped in `safe()`

---

## Migration Map

### SavingsAccountController — `charge()` method

Before:
```php
$this->accounting->postDepositCharge(
    memberId: (int) $savingsAccount->member_id,
    savingsAccountId: (int) $savingsAccount->id,
    chargeAmount: (float) $validated['amount'],
    reference: $ref,
    date: $validated['charge_date'],
    narration: $narration,
);
```

After (Transaction `$chargeTxn` is already created a few lines above):
```php
$chargeTxn = Transaction::where('reference', $ref)->first();
$this->savingsJournal->postCharge($chargeTxn, $savingsAccount);
```

### SavingsAccountController — `deposit()` method

Before:
```php
app(MemberChargeService::class)->collectPendingCharges(
    $member,
    $savingsAccount,
    $this->accounting,
);
```

After:
```php
app(MemberChargeService::class)->collectPendingCharges(
    $member,
    $savingsAccount,
    $this->savingsJournal,
);
```

Remove `protected AccountingService $accounting` from constructor. Remove `use AccountingService` import.

### MemberChargeService

Before:
```php
public function collectPendingCharges(Member $member, SavingsAccount $account, AccountingService $accounting): void
public function collectCharge(MemberCharge $memberCharge, SavingsAccount $account, AccountingService $accounting): bool
```
```php
$accounting->postDepositCharge(memberId: ..., savingsAccountId: ..., chargeAmount: ..., reference: ..., date: ..., narration: ..., creditAccountId: ...);
```

After:
```php
public function collectPendingCharges(Member $member, SavingsAccount $account, SavingsJournalService $savingsJournal): void
public function collectCharge(MemberCharge $memberCharge, SavingsAccount $account, SavingsJournalService $savingsJournal): bool
```
```php
$savingsJournal->postCharge($transaction, $account);
```
The `$transaction` variable is already created on the line above the GL call. `creditAccountId` is stored on `$transaction->gl_credit_account_id` — `postCharge()` uses `SavingsCoaResolverInterface` to resolve the credit GL from the account's product, which is correct behaviour. The `gl_credit_account_id` on the Transaction record is already set before the GL call and serves as the audit trail.

Update `use` import: replace `AccountingService` with `SavingsJournalService`.

### TransactionController — `postReversalAccounting()`

Before (primary path):
```php
$this->accounting->reverseJournalEntry(
    original: $originalJe,
    reversalReference: $reversalRef,
    date: now()->toDateString(),
    reversedBy: auth()->id(),
    narration: "Reversal of {$txn->type} txno:{$txn->reference}",
);
```
After:
```php
$this->savingsJournal->reverseJournalEntry(
    original: $originalJe,
    reversalReference: $reversalRef,
    date: now()->toDateString(),
    reversedBy: auth()->id(),
    narration: "Reversal of {$txn->type} txno:{$txn->reference}",
);
```

Before (fallback deposit/withdrawal):
```php
$this->accounting->postWithdrawal(memberId: $memberId, savingsAccountId: $savingsId, ...)
$this->accounting->postDeposit(memberId: $memberId, savingsAccountId: $savingsId, ...)
```
After — the `$reversalTxn` and `$savingsAccount` are already in scope, use them directly:
```php
// deposit reversal fallback
$reversalTxn
    ? $this->savingsJournal->postWithdrawal($reversalTxn, $savingsAccount)
    : null; // no JE if data missing — Log::warning

// withdrawal reversal fallback
$reversalTxn
    ? $this->savingsJournal->postDeposit($reversalTxn, $savingsAccount)
    : null;
```

Before (fallback charge reversal):
```php
'charge' => $this->accounting->postChargeReversal(
    memberId: $memberId, savingsAccountId: $savingsId,
    amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
    debitAccountId: $txn->gl_credit_account_id,
),
```
After:
```php
'charge' => $reversalTxn && $savingsAccount
    ? $this->savingsJournal->postChargeReversal($reversalTxn, $savingsAccount)
    : null,
```

Remove `protected AccountingService $accounting` from constructor. Remove `use AccountingService` import. `SavingsJournalService $savingsJournal` is already injected.

### ReversalService

Identical migration as `TransactionController::postReversalAccounting()` above — both have the exact same three-path `match` block. Apply the same substitutions.

Remove `protected AccountingService $accounting` from constructor. `SavingsJournalService $savingsJournal` is already injected.

### MemberController + MemberChargeController

Remove `protected AccountingService $accounting` from constructor parameters. Remove `use AccountingService` import. No other changes — neither controller calls any `accounting->*` methods.

---

## Tests to Write

File: `tests/Feature/Accounting/AccountingServiceMigrationTest.php`

```php
it('posts mandatory savings charge to GL 2111, not 2112', function () {
    // Setup: mandatory savings account, manual charge via SavingsAccountController
    // Assert: JournalEntryLine where account_id = GL 2111 (not 2112) with debit = charge amount
});

it('member registration charge posts to correct liability GL', function () {
    // Setup: mandatory savings account, pending MemberCharge
    // Trigger: MemberChargeService::collectCharge()
    // Assert: GL debit = 2111, GL credit = creditAccountId from generalCharge
});

it('reverseJournalEntry mirrors original JE and marks it reversed', function () {
    // Setup: post a deposit JE, then call savingsJournal->reverseJournalEntry()
    // Assert: new JE lines are swapped DR/CR; original JE status = 'reversed'
});

it('postChargeReversal DRs income GL and CRs correct savings liability', function () {
    // Setup: charge Transaction with gl_credit_account_id set, mandatory savings account
    // Assert: JE line 1 = DR gl_credit_account_id; JE line 2 = CR GL 2111
});

it('AccountingService is not bound in the container', function () {
    // Assert: app(AccountingService::class) throws BindingResolutionException
});
```

---

## Deletion

After all migrations pass and tests are green:

```bash
rm app/Tenant/Modules/Accounting/Services/AccountingService.php
```

Confirm no remaining references:
```bash
grep -r "AccountingService" app/ --include="*.php"
# expected: zero results
```
