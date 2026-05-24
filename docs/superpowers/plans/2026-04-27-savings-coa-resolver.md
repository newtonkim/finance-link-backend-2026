# SavingsCoaResolver Extraction — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the three private COA-resolver methods from `SavingsJournalService` into a new injectable `SavingsCoaResolver` class, bringing the service under 400 lines.

**Architecture:** Create `SavingsCoaResolver` with three public methods moved verbatim from `SavingsJournalService`. Inject it into `SavingsJournalService` constructor and replace all private call sites with `$this->coa->...`. No behaviour changes.

**Tech Stack:** Laravel 12, PHP 8.2, tenant DB (`ChartOfAccount` model).

---

## File Map

| File | Action |
|------|--------|
| `app/Tenant/Modules/Accounting/Services/SavingsCoaResolver.php` | Create — 3 public resolver methods |
| `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` | Inject `SavingsCoaResolver`, remove 3 private methods, update 6 call sites |

---

## Task 1 — Create `SavingsCoaResolver`

**Files:**
- Create: `app/Tenant/Modules/Accounting/Services/SavingsCoaResolver.php`

- [ ] **Step 1: Create the file with exact content**

```php
<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

class SavingsCoaResolver
{
    public function resolvePaymentModeAccount(string $mode): ChartOfAccount
    {
        $glCode = match (strtolower(trim($mode))) {
            'cash', 'petty_cash'                     => '1111',
            'mobile_money', 'mtn', 'mobile_money_mtn' => '1114',
            'airtel', 'mobile_money_airtel'           => '1115',
            default                                   => '1112',
        };

        return $this->resolveByGlCode($glCode);
    }

    public function resolveSavingsLiabilityAccount(SavingsAccount $account): ChartOfAccount
    {
        $accountType = strtolower($account->account_type ?? '');
        $productType = strtolower($account->savingsProduct?->type ?? '');
        $type = $accountType ?: $productType;

        $glCode = match (true) {
            str_contains($type, 'mandatory') => '2111',
            str_contains($type, 'fixed')     => '2113',
            default                          => '2112',
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

- [ ] **Step 2: Lint**

```bash
composer test:lint
```

Expected: `SavingsCoaResolver.php` not in the failure list.

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Accounting/Services/SavingsCoaResolver.php
git commit -m "feat: add SavingsCoaResolver — extracts COA resolution from SavingsJournalService"
```

---

## Task 2 — Update `SavingsJournalService` to inject and delegate

**Files:**
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php`

- [ ] **Step 1: Add `SavingsCoaResolver` to the constructor**

Current constructor (lines 15–18):
```php
public function __construct(
    private readonly JournalSequenceService $sequence,
    private readonly GlPostingEngine $gl,
) {}
```

Replace with:
```php
public function __construct(
    private readonly JournalSequenceService $sequence,
    private readonly GlPostingEngine $gl,
    private readonly SavingsCoaResolver $coa,
) {}
```

- [ ] **Step 2: Replace the 6 private resolver call sites**

There are 6 calls to replace (lines 29, 30, 52, 53, 76–77, 100–101). Apply each replacement:

Line 29: `$this->resolvePaymentModeAccount(` → `$this->coa->resolvePaymentModeAccount(`
Line 30: `$this->resolveSavingsLiabilityAccount(` → `$this->coa->resolveSavingsLiabilityAccount(`
Line 52: `$this->resolvePaymentModeAccount(` → `$this->coa->resolvePaymentModeAccount(`
Line 53: `$this->resolveSavingsLiabilityAccount(` → `$this->coa->resolveSavingsLiabilityAccount(`
Line 76: `$this->resolveSavingsLiabilityAccount(` → `$this->coa->resolveSavingsLiabilityAccount(`
Line 77: `$this->resolveByGlCode(` → `$this->coa->resolveByGlCode(`
Line 100: `$this->resolveByGlCode(` → `$this->coa->resolveByGlCode(`
Line 101: `$this->resolveSavingsLiabilityAccount(` → `$this->coa->resolveSavingsLiabilityAccount(`

- [ ] **Step 3: Delete the three private resolver methods**

Remove the entire `// ── COA resolvers ────` section (lines 256–295):

```php
    // ── COA resolvers ─────────────────────────────────────────────────────────

    private function resolvePaymentModeAccount(string $mode): ChartOfAccount
    { ... }

    private function resolveSavingsLiabilityAccount(SavingsAccount $account): ChartOfAccount
    { ... }

    private function resolveByGlCode(string $glCode): ChartOfAccount
    { ... }
```

- [ ] **Step 4: Verify line count is under 400**

```bash
wc -l app/Tenant/Modules/Accounting/Services/SavingsJournalService.php
```

Expected: under 400.

- [ ] **Step 5: Lint**

```bash
composer test:lint
```

Expected: `SavingsJournalService.php` not in the failure list.

- [ ] **Step 6: Commit**

```bash
git add app/Tenant/Modules/Accounting/Services/SavingsJournalService.php
git commit -m "refactor: delegate COA resolution to SavingsCoaResolver, trim service to <400 lines"
```
