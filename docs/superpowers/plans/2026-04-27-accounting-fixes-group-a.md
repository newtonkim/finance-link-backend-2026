# Accounting Fixes Group A — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix three broken GL/SubLedger posting flows — FD interest SubLedger gap (#8), savings transfer posts no journal entry (#9), and opening balance debiting wrong equity account (#14).

**Architecture:** All changes are surgical edits to `SavingsJournalService`, `SavingsTransferController`, and `SaccoCoaSeeder`. No new services or classes are created. The canonical `GlPostingEngine` and `JournalSequenceService` already exist and are injected into `SavingsJournalService`.

**Tech Stack:** Laravel 12, PHP 8.2, MySQL tenant DB (`tenant` connection), `GlPostingEngine`, `JournalSequenceService`, `SavingsJournalService`.

---

## File Map

| File | Change |
|------|--------|
| `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` | Fix `postInterest()`, fix `postOpeningBalance()`, add `postTransfer()`, update `makeJe()` |
| `app/Tenant/Http/Controllers/Api/V1/SavingsTransferController.php` | Inject `SavingsJournalService`, call `postTransfer()` inside DB transaction |
| `database/seeders/SaccoCoaSeeder.php` | Add GL 3390 row after 3330 |

---

## Task 1 — Fix `postInterest()` SubLedger gap (Issue #8)

**Context:** `SavingsJournalService::postInterest()` (lines 122–151) has two bugs:
1. The DR expense line is created with no `memberId`/`savingsId`, so `postToSubLedger` is never called for it.
2. The CR line calls `$this->postToSubLedger(...)` — a **private method that was deleted** in a prior refactor — causing a runtime fatal.

Fix: pass `$account->member_id` and `$account->id` to both `createLine()` calls, then explicitly call `$this->gl->postToSubLedger()` after each. Remove the broken call entirely.

Also update `makeJe()` to accept `?int $postedBy` so system-generated entries (no actor) can pass `null` without type errors.

**Files:**
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php:122-151` (postInterest)
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php:300-315` (makeJe signature)

- [ ] **Step 1: Update `makeJe()` signature to accept nullable posted_by**

In `SavingsJournalService.php`, change the `makeJe()` signature at line ~300 from:

```php
private function makeJe(string $date, string $reference, string $narration, string $journalType, int $postedBy): JournalEntry
```

to:

```php
private function makeJe(string $date, string $reference, string $narration, string $journalType, ?int $postedBy): JournalEntry
```

- [ ] **Step 2: Replace `postInterest()` body**

Replace the entire `postInterest()` method (lines 122–151) with:

```php
public function postInterest(
    SavingsAccount $account,
    ChartOfAccount $expenseAccount,
    ChartOfAccount $creditAccount,
    float $interestAmount,
    string $periodStart,
    string $periodEnd,
    int $actorId,
): ?JournalEntry {
    return $this->safe(function () use (
        $account, $expenseAccount, $creditAccount,
        $interestAmount, $periodStart, $periodEnd, $actorId
    ) {
        $date = now()->toDateString();
        $narration = "FD interest – {$account->account_no} ({$periodStart} → {$periodEnd})";

        $je = $this->makeJe($date, 'INT-'.$account->account_no, $narration, 'FD_INTEREST', $actorId);

        // DR Interest Expense — SubLedger: member's savings account
        $this->createLine($je, $expenseAccount, $interestAmount, 0.0, $narration, 1, $date, $account->member_id, $account->id);
        if ($account->member_id) {
            $this->gl->postToSubLedger(
                $je->id, $expenseAccount->id, $account->id, SavingsAccount::class,
                $interestAmount, 0.0, $date, $narration, $expenseAccount->normal_balance ?? 'DR',
            );
        }

        // CR FD Liability or Payout Savings account — SubLedger: member's savings account
        $this->createLine($je, $creditAccount, 0.0, $interestAmount, $narration, 2, $date, $account->member_id, $account->id);
        if ($account->member_id) {
            $this->gl->postToSubLedger(
                $je->id, $creditAccount->id, $account->id, SavingsAccount::class,
                0.0, $interestAmount, $date, $narration, $creditAccount->normal_balance ?? 'CR',
            );
        }

        return $je;
    });
}
```

- [ ] **Step 3: Verify lint passes**

```bash
composer test:lint
```

Expected: no errors reported.

- [ ] **Step 4: Commit**

```bash
git add app/Tenant/Modules/Accounting/Services/SavingsJournalService.php
git commit -m "fix: postInterest() now posts SubLedger for both DR and CR legs (#8)"
```

---

## Task 2 — Add `postTransfer()` to `SavingsJournalService` (Issue #9, part 1)

**Context:** There is no method to post a GL entry for savings-to-savings transfers. This adds it to `SavingsJournalService`.

Double entry:
```
DR  source savings liability GL  (2111/2112/2113 per fromAccount type)  [SubLedger: fromAccount.member_id / Member]
CR  destination savings liability GL  (2111/2112/2113 per toAccount type)  [SubLedger: toAccount.member_id / Member]
```

Both GL codes are resolved by the existing `resolveSavingsLiabilityAccount()` helper. If both accounts are the same type (e.g., both voluntary → 2112), the net GL movement is zero — correct for an internal transfer. SubLedger still records the per-member debit/credit.

**Files:**
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` — add new public method `postTransfer()`

- [ ] **Step 1: Add `postTransfer()` method to `SavingsJournalService`**

Add the following method after `postOpeningBalance()` and before `postReversal()` (around line 115):

```php
/**
 * DR Source savings liability GL  CR Destination savings liability GL
 * Journal type: SAVINGS_TRANSFER
 */
public function postTransfer(
    SavingsAccount $fromAccount,
    SavingsAccount $toAccount,
    float $amount,
    string $reference,
    string $date,
    ?string $narration = null,
    ?int $actorId = null,
): ?JournalEntry {
    return $this->safe(function () use ($fromAccount, $toAccount, $amount, $reference, $date, $narration, $actorId) {
        $fromAccount->loadMissing('savingsProduct');
        $toAccount->loadMissing('savingsProduct');

        $fromGl = $this->resolveSavingsLiabilityAccount($fromAccount);
        $toGl   = $this->resolveSavingsLiabilityAccount($toAccount);
        $note   = $narration ?? "Savings transfer from {$fromAccount->account_no} to {$toAccount->account_no}";

        $je = $this->makeJe($date, $reference, $note, 'SAVINGS_TRANSFER', $actorId);

        // DR source savings liability
        $this->createLine($je, $fromGl, $amount, 0.0, $note, 1, $date, $fromAccount->member_id, $fromAccount->id);
        if ($fromAccount->member_id) {
            $this->gl->postToSubLedger(
                $je->id, $fromGl->id, $fromAccount->member_id, Member::class,
                $amount, 0.0, $date, $note, $fromGl->normal_balance ?? 'CR',
            );
        }

        // CR destination savings liability
        $this->createLine($je, $toGl, 0.0, $amount, $note, 2, $date, $toAccount->member_id, $toAccount->id);
        if ($toAccount->member_id) {
            $this->gl->postToSubLedger(
                $je->id, $toGl->id, $toAccount->member_id, Member::class,
                0.0, $amount, $date, $note, $toGl->normal_balance ?? 'CR',
            );
        }

        return $je;
    });
}
```

- [ ] **Step 2: Verify lint passes**

```bash
composer test:lint
```

Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Accounting/Services/SavingsJournalService.php
git commit -m "feat: add postTransfer() to SavingsJournalService for GL posting (#9)"
```

---

## Task 3 — Wire `postTransfer()` into `SavingsTransferController` (Issue #9, part 2)

**Context:** `SavingsTransferController::store()` updates balances and inserts transaction rows but calls no accounting service. Inject `SavingsJournalService` and call `postTransfer()` inside the existing DB transaction, after the balance updates.

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/SavingsTransferController.php`

- [ ] **Step 1: Add import and constructor to `SavingsTransferController`**

At the top of `SavingsTransferController.php`, add this import after the existing `use` statements:

```php
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
```

Add a constructor before the `accounts()` method:

```php
public function __construct(
    private readonly SavingsJournalService $journal,
) {}
```

- [ ] **Step 2: Call `postTransfer()` inside the DB transaction in `store()`**

Inside the `DB::connection('tenant')->transaction(function () use (...) { ... })` closure in `store()`, add the `postTransfer()` call after the two `transactions` table inserts (after line ~136, before the closing `}`):

```php
// Post GL journal entry for the transfer
$this->journal->postTransfer(
    fromAccount: $fromAccount,
    toAccount: $toAccount,
    amount: $amount,
    reference: $ref,
    date: $date,
    narration: $narration ?: null,
    actorId: $userId,
);
```

The complete updated closure will look like this:

```php
DB::connection('tenant')->transaction(function () use (
    $fromAccount, $toAccount, $amount, $validated
) {
    $narration = $validated['narration'] ?? '';
    $date = $validated['transfer_date'];
    $ref = $validated['transaction_reference'];
    $userId = auth()->id();
    $branchId = BranchContext::actingBranchId();

    // Debit source
    $fromAccount->balance -= $amount;
    $fromAccount->save();

    // Credit destination
    $toAccount->balance += $amount;
    $toAccount->save();

    // Debit transaction on source account
    \DB::connection('tenant')->table('transactions')->insert([
        'reference' => $ref,
        'member_id' => $fromAccount->member_id,
        'type' => 'transfer_out',
        'amount' => $amount,
        'payment_mode' => 'internal_transfer',
        'deposited_by' => 'System',
        'transaction_date' => $date,
        'account_id' => $fromAccount->id,
        'account_type' => SavingsAccount::class,
        'narration' => "Transfer to {$toAccount->account_no}. {$narration}",
        'created_by' => $userId,
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Credit transaction on destination account
    \DB::connection('tenant')->table('transactions')->insert([
        'reference' => 'TRF-IN-'.substr($ref, 0, 80),
        'member_id' => $toAccount->member_id,
        'type' => 'transfer_in',
        'amount' => $amount,
        'payment_mode' => 'internal_transfer',
        'deposited_by' => 'System',
        'transaction_date' => $date,
        'account_id' => $toAccount->id,
        'account_type' => SavingsAccount::class,
        'narration' => "Transfer from {$fromAccount->account_no}. {$narration}",
        'created_by' => $userId,
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Post GL journal entry for the transfer
    $this->journal->postTransfer(
        fromAccount: $fromAccount,
        toAccount: $toAccount,
        amount: $amount,
        reference: $ref,
        date: $date,
        narration: $narration ?: null,
        actorId: $userId,
    );
});
```

- [ ] **Step 3: Verify lint passes**

```bash
composer test:lint
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/SavingsTransferController.php
git commit -m "feat: inject SavingsJournalService into SavingsTransferController, call postTransfer() (#9)"
```

---

## Task 4 — Add GL 3390 to seeder + fix `postOpeningBalance()` (Issue #14)

**Context:** `postOpeningBalance()` currently resolves GL `'3310'` (Retained Earnings – Prior Years) as the DR account for migration entries. This pollutes the P&L earnings account with data-migration noise. The correct account is `'3390'` (Opening Balance Control), an equity clearing account that must be added to `SaccoCoaSeeder`.

**Files:**
- Modify: `database/seeders/SaccoCoaSeeder.php` — add GL 3390 row after 3330
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php:100` — change `'3310'` to `'3390'`

- [ ] **Step 1: Add GL 3390 to `SaccoCoaSeeder`**

In `database/seeders/SaccoCoaSeeder.php`, inside `getSaccoUgandaAccounts()`, find the 3330 row:

```php
['gl_code' => '3330', 'name' => 'Dividends Declared', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
```

Insert the following new row immediately after it:

```php
['gl_code' => '3390', 'name' => 'Opening Balance Control', 'account_type' => 'EQUITY', 'account_subtype' => 'Retained Earnings', 'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true],
```

- [ ] **Step 2: Update `postOpeningBalance()` to use GL 3390**

In `SavingsJournalService.php`, find `postOpeningBalance()` at line ~96. Change:

```php
$retained = $this->resolveByGlCode('3310'); // Retained Earnings – Prior Years
```

to:

```php
$retained = $this->resolveByGlCode('3390'); // Opening Balance Control
```

Also update the docblock comment above the method from:

```php
* DR Retained Earnings (3310)  CR Member Savings Liability
```

to:

```php
* DR Opening Balance Control (3390)  CR Member Savings Liability
```

- [ ] **Step 3: Re-seed the COA template**

```bash
php artisan db:seed --class=SaccoCoaSeeder
```

Expected output:
```
Seeded 92 accounts into SACCO_UGANDA template.
```

> **Note for existing tenants:** The seeder populates `coa_template_accounts` on the master DB. For existing tenants whose `chart_of_accounts` table is already provisioned, insert GL 3390 directly:
>
> ```sql
> INSERT INTO chart_of_accounts (gl_code, name, account_type, account_subtype, normal_balance, level, is_control, is_postable, is_active, created_at, updated_at)
> VALUES ('3390', 'Opening Balance Control', 'EQUITY', 'Retained Earnings', 'CR', 3, 0, 1, 1, NOW(), NOW());
> ```
>
> Or run the tenant-level COA seeder if one exists: `php artisan tenants:seed --class=SaccoCoaSeeder`

- [ ] **Step 4: Verify lint passes**

```bash
composer test:lint
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/SaccoCoaSeeder.php app/Tenant/Modules/Accounting/Services/SavingsJournalService.php
git commit -m "fix: add GL 3390 Opening Balance Control, use it in postOpeningBalance() instead of 3310 (#14)"
```

---

## Self-Review Checklist

**Spec coverage:**
- ✅ #8 — `postInterest()` both legs now post SubLedger via `$this->gl->postToSubLedger()`; broken `$this->postToSubLedger()` call removed.
- ✅ #9 — `postTransfer()` added to `SavingsJournalService`; injected and called in `SavingsTransferController::store()` inside DB transaction.
- ✅ #14 — GL 3390 added to seeder; `postOpeningBalance()` resolves `'3390'` instead of `'3310'`.

**Type consistency:**
- `postTransfer()` signature uses `SavingsAccount`, `float`, `string`, `?string`, `?int` — all available from existing imports.
- `makeJe()` updated to `?int $postedBy` — compatible with all existing call sites (they all pass a concrete int; `null` is only passed from `postTransfer()` when `$actorId` is `null`).
- `postToSubLedger()` calls use `SavingsAccount::class` (imported) for interest entries and `Member::class` (imported) for transfer entries — consistent with the spec.

**No placeholders:** All steps contain complete code.
