# Regular Savings Interest Posting — Design Spec

**Date:** 2026-04-28
**Issue:** #10 — Regular savings interest has no posting flow
**Approach:** New `RegularSavingsInterestService` mirroring the FD pattern

---

## Problem

Mandatory and voluntary savings accounts have no interest calculation or GL posting flow. `SavingsProduct` already carries the necessary fields (`interest_rate`, `interest_posting_frequency`, `interest_expense_account_id`, `interest_payable_account_id`) but nothing consumes them for non-FD account types.

---

## Scope

- Add product-level `interest_enabled` flag to opt products in
- Add global on/off toggle in `OnboardingSettings`
- Build `RegularSavingsInterestService` (new service, FD path untouched)
- Expose via API endpoint (result shown in UI) and artisan command (cron-safe)
- Full GL + SubLedger double-entry posting via existing `SavingsJournalService`
- Test coverage for all guard conditions and the happy path

Out of scope: per-account interest rate override, dividend posting, FD changes.

---

## Database Changes

### Migration 1 — `savings_products`

```
+ interest_enabled  BOOLEAN  NOT NULL  DEFAULT false
```

When `true`, accounts belonging to this product are included in the batch. The existing `interest_rate`, `interest_posting_frequency`, `interest_expense_account_id`, and `interest_payable_account_id` fields (added with FD) are reused — no new columns needed for the rate or GL side.

### Migration 2 — `onboarding_settings`

```
+ regular_savings_interest_enabled  BOOLEAN  NOT NULL  DEFAULT false
```

Master on/off switch. When `false`, `runBatchPosting()` returns immediately regardless of product settings.

### Model updates

**`SavingsProduct`**
- Add `interest_enabled` to `$fillable` and `$casts` (`boolean`)

**`OnboardingSettings`**
- Add `regular_savings_interest_enabled` to `$fillable` and `$casts` (`boolean`)

---

## Service Layer

### `RegularSavingsInterestService`

**Location:** `app/Tenant/Modules/Savings/Services/RegularSavingsInterestService.php`

**Constructor dependencies:**
- `FixedDepositCalculator` — reused unchanged for interest calculation
- `SavingsJournalService` — reused unchanged for GL posting

#### `processAccount(SavingsAccount $account, int $actorId): void`

Guards (returns early if any fail):
- `account_type` is `mandatory` or `voluntary`
- `status` is `active`
- Product has `interest_enabled = true`
- Product `interest_rate > 0`
- Product `interest_expense_account_id` is set
- Product `interest_payable_account_id` is set

Calculation:
```
period_start = last_interest_posted_at ?? account.created_at
period_end   = today
days         = period_start → period_end (diffInDays)
interest     = balance × annual_rate × (days / 365)   [simple interest]
```

If `interest <= 0`, returns early.

Actions (wrapped in `DB::transaction` + `lockForUpdate`):
1. Call `SavingsJournalService::postInterest()` → creates JournalEntry + GL + SubLedger lines
2. Create `SavingsInterestPosting` record
3. Increment `account.balance` by interest amount
4. Update `account.last_interest_posted_at = now()`

Idempotency: `savings_interest_postings` has a unique constraint on `(savings_account_id, period_end)`. A duplicate call throws `UniqueConstraintViolationException`, which `runBatchPosting()` catches and counts as `skipped`.

#### Journal entry posted

```
DR  product.interest_expense_account_id   = interest   (expense, normal_balance DR)
CR  product.interest_payable_account_id   = interest   (liability, normal_balance CR)
```

After posting, `account.balance += interest` (the liability is now owed to the member and reflected on their balance).

#### `runBatchPosting(int $actorId): array`

1. Check `OnboardingSettings::regular_savings_interest_enabled` — return `['posted'=>0,'skipped'=>0,'errors'=>[]]` if false
2. Query active mandatory/voluntary accounts with `product.interest_enabled = true` (eager-loads product)
3. Call `processAccount()` per account
4. Catch `UniqueConstraintViolationException` → `skipped++`
5. Catch `Throwable` → append to `errors[]`, log via `Log::error()`
6. Return summary array: `['posted' => N, 'skipped' => N, 'errors' => [...]]`

---

## API Endpoint

**Controller:** `app/Tenant/Http/Controllers/Api/V1/RegularSavingsInterestController.php`

**Route:**
```
POST /api/v1/tenant/savings/regular-interest/post-interest
```

Added to `routes/tenant_api.php` alongside the existing FD endpoint:
```php
Route::post('savings/regular-interest/post-interest', [RegularSavingsInterestController::class, 'postInterest']);
```

**Response (200):**
```json
{
  "message": "Interest posting complete",
  "summary": {
    "posted": 42,
    "skipped": 3,
    "errors": []
  }
}
```

The frontend displays the summary to staff. No request body required.

---

## Artisan Command

**Class:** `app/Console/Commands/PostRegularSavingsInterest.php`
**Signature:** `savings:post-regular-interest`

- Resolves `RegularSavingsInterestService` from container
- Calls `runBatchPosting(actorId: 1)` (system actor)
- Logs summary to Laravel log (`Log::info()`)
- Exits with code `1` if `errors` array is non-empty (safe for cron monitoring)

Suggested schedule in `routes/console.php`:
```php
Schedule::command('savings:post-regular-interest')->monthlyOn(last_day, '23:00');
```

---

## Reused Without Modification

| Component | Reuse |
|-----------|-------|
| `FixedDepositCalculator::calculateInterest()` | Same simple-interest formula |
| `FixedDepositCalculator::nextInterestDate()` | Used if frequency-based scheduling needed in future |
| `SavingsJournalService::postInterest()` | Already generic — not FD-specific |
| `SavingsInterestPosting` model | Already generic |

---

## Tests

**File:** `tests/Feature/Accounting/RegularSavingsInterestGlTest.php`

| Test | Asserts |
|------|---------|
| `test_interest_is_posted_when_product_interest_enabled` | `SavingsInterestPosting` created, JE balanced, SubLedger entries present, `account.balance` incremented, `last_interest_posted_at` updated |
| `test_skips_account_when_global_setting_disabled` | `OnboardingSettings.regular_savings_interest_enabled = false` → zero postings |
| `test_skips_account_when_product_interest_disabled` | `product.interest_enabled = false` → zero postings |
| `test_skips_fixed_deposit_accounts` | FD account passed to `processAccount()` → returns early, nothing posted |
| `test_does_not_double_post_same_period` | `processAccount()` called twice → only one `SavingsInterestPosting` record |

---

## Files Changed / Created

| Action | Path |
|--------|------|
| CREATE migration | `database/migrations/tenant/2026_04_28_000005_add_interest_enabled_to_savings_products.php` |
| CREATE migration | `database/migrations/tenant/2026_04_28_000006_add_regular_savings_interest_enabled_to_onboarding_settings.php` |
| EDIT model | `app/Tenant/Modules/Savings/Models/SavingsProduct.php` |
| EDIT model | `app/Tenant/Modules/Settings/Models/OnboardingSettings.php` |
| CREATE service | `app/Tenant/Modules/Savings/Services/RegularSavingsInterestService.php` |
| CREATE controller | `app/Tenant/Http/Controllers/Api/V1/RegularSavingsInterestController.php` |
| CREATE command | `app/Console/Commands/PostRegularSavingsInterest.php` |
| EDIT routes | `routes/tenant_api.php` |
| CREATE test | `tests/Feature/Accounting/RegularSavingsInterestGlTest.php` |
