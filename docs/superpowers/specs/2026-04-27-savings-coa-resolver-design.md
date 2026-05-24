# SavingsCoaResolver Extraction — Design Spec

**Goal:** Extract the three COA-resolution private methods from `SavingsJournalService` into a dedicated injectable class, bringing the service under 400 lines and making the resolvers available to other services (specifically `RegularSavingsInterestService`).

**Architecture:** Single new class `SavingsCoaResolver` in the Accounting services layer. `SavingsJournalService` gets `SavingsCoaResolver` injected in its constructor and delegates all three resolver calls to it. No other behaviour changes.

**Tech Stack:** Laravel 12, PHP 8.2, tenant DB connection.

---

## Problem

`SavingsJournalService` is ~428 lines (28 over the 400-line class limit) after Group A changes. The three private resolver methods account for ~40 lines and have a distinct single responsibility — mapping account types and payment modes to `ChartOfAccount` records.

## Fix

### New file — `app/Tenant/Modules/Accounting/Services/SavingsCoaResolver.php`

Contains exactly three public methods moved verbatim from `SavingsJournalService`:

```php
namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

class SavingsCoaResolver
{
    public function resolvePaymentModeAccount(string $mode): ChartOfAccount
    {
        $glCode = match (strtolower(trim($mode))) {
            'cash', 'petty_cash'                    => '1111',
            'mobile_money', 'mtn', 'mobile_money_mtn' => '1114',
            'airtel', 'mobile_money_airtel'          => '1115',
            default                                  => '1112',
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
            ->where('is_active', true)->first();

        if (! $account) {
            throw new \RuntimeException(
                "COA entry missing for GL code {$glCode}. Run the Chart of Accounts seeder."
            );
        }
        return $account;
    }
}
```

### Changes to `SavingsJournalService`

1. Add `SavingsCoaResolver $coa` to constructor (alongside `JournalSequenceService` and `GlPostingEngine`).
2. Delete the three private resolver methods.
3. Replace every call site:
   - `$this->resolvePaymentModeAccount(...)` → `$this->coa->resolvePaymentModeAccount(...)`
   - `$this->resolveSavingsLiabilityAccount(...)` → `$this->coa->resolveSavingsLiabilityAccount(...)`
   - `$this->resolveByGlCode(...)` → `$this->coa->resolveByGlCode(...)`

**Result:** `SavingsJournalService` drops to ~388 lines. `SavingsCoaResolver` is ~40 lines. No behavioural change.

---

## Files Changed

| File | Action |
|------|--------|
| `app/Tenant/Modules/Accounting/Services/SavingsCoaResolver.php` | Create |
| `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` | Remove 3 private methods, inject `SavingsCoaResolver`, update call sites |
