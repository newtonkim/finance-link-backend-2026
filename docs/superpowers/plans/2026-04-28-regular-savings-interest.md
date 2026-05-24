# Regular Savings Interest — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `RegularSavingsInterestService` that calculates and posts simple interest for voluntary/mandatory savings accounts, exposed via an API endpoint and an Artisan command, with per-product opt-in and a global on/off toggle.

**Architecture:** Two new migrations add `interest_enabled` to `savings_products` and `regular_savings_interest_enabled` to `onboarding_settings`. A new service (`RegularSavingsInterestService`) mirrors `FixedDepositInterestService` — reuses `FixedDepositCalculator` for the formula and `SavingsJournalService::postInterest()` for GL posting. A slim controller exposes it via `POST /api/v1/tenant/savings/regular-interest/post-interest`. An Artisan command (`savings:post-regular-interest`) loops all active tenants using the `DatabaseSwitcher` pattern from `CheckLoanArrearsCommand`.

**Tech Stack:** Laravel 12, PHP 8.2, tenant DB connection, `FixedDepositCalculator`, `SavingsJournalService`, `SavingsInterestPosting`, `OnboardingSettings`, `DatabaseSwitcher`, Pest/PHPUnit feature tests.

---

## File Map

| File | Action |
|------|--------|
| `database/migrations/tenant/2026_04_28_000005_add_interest_enabled_to_savings_products.php` | Create |
| `database/migrations/tenant/2026_04_28_000006_add_regular_savings_interest_enabled_to_onboarding_settings.php` | Create |
| `app/Tenant/Modules/Savings/Models/SavingsProduct.php` | Edit — add `interest_enabled` to `$fillable` + `$casts` |
| `app/Tenant/Modules/Settings/Models/OnboardingSettings.php` | Edit — add `regular_savings_interest_enabled` to `$fillable`, `$casts`, and `firstOrCreate` defaults |
| `app/Tenant/Modules/Savings/Services/RegularSavingsInterestService.php` | Create |
| `app/Tenant/Http/Controllers/Api/V1/RegularSavingsInterestController.php` | Create |
| `app/Console/Commands/PostRegularSavingsInterest.php` | Create |
| `routes/tenant_api.php` | Edit — add route beside FD endpoint |
| `tests/Feature/Accounting/RegularSavingsInterestGlTest.php` | Create |
| `tests/TenantTestCase.php` | Edit — add new-column check so migrations run in test env |

---

## Task 1 — Migrations and Model Updates

**Files:**
- Create: `database/migrations/tenant/2026_04_28_000005_add_interest_enabled_to_savings_products.php`
- Create: `database/migrations/tenant/2026_04_28_000006_add_regular_savings_interest_enabled_to_onboarding_settings.php`
- Modify: `app/Tenant/Modules/Savings/Models/SavingsProduct.php`
- Modify: `app/Tenant/Modules/Settings/Models/OnboardingSettings.php`
- Modify: `tests/TenantTestCase.php`

- [ ] **Step 1: Create migration — `interest_enabled` on `savings_products`**

Create `database/migrations/tenant/2026_04_28_000005_add_interest_enabled_to_savings_products.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('savings_products', function (Blueprint $table) {
            $table->boolean('interest_enabled')->default(false)->after('interest_payable_account_id');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('savings_products', function (Blueprint $table) {
            $table->dropColumn('interest_enabled');
        });
    }
};
```

- [ ] **Step 2: Create migration — `regular_savings_interest_enabled` on `onboarding_settings`**

Create `database/migrations/tenant/2026_04_28_000006_add_regular_savings_interest_enabled_to_onboarding_settings.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            $table->boolean('regular_savings_interest_enabled')->default(false)->after('reversal_max_days');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            $table->dropColumn('regular_savings_interest_enabled');
        });
    }
};
```

- [ ] **Step 3: Run migrations against the dev database**

```bash
cd /path/to/mfuko-pro-backend-2026
php artisan migrate --path=database/migrations/tenant --database=tenant
```

Expected: both new migrations listed as "Migrating" then "Migrated".

- [ ] **Step 4: Update `SavingsProduct` model**

In `app/Tenant/Modules/Savings/Models/SavingsProduct.php`, add `'interest_enabled'` to `$fillable` after `'interest_payable_account_id'`:

```php
    protected $fillable = [
        // ... existing fields ...
        'interest_expense_account_id',
        'interest_payable_account_id',
        'interest_enabled',          // ← add this
    ];
```

And add to `$casts` after `'interest_payable_account_id' => 'integer'`:

```php
    protected $casts = [
        // ... existing casts ...
        'interest_expense_account_id' => 'integer',
        'interest_payable_account_id' => 'integer',
        'interest_enabled' => 'boolean',             // ← add this
    ];
```

- [ ] **Step 5: Update `OnboardingSettings` model**

In `app/Tenant/Modules/Settings/Models/OnboardingSettings.php`:

Add `'regular_savings_interest_enabled'` to `$fillable` after `'reversal_max_days'`:

```php
    protected $fillable = [
        // ... existing fields ...
        'reversal_max_days',
        'regular_savings_interest_enabled',   // ← add this
    ];
```

Add to `$casts` after `'reversal_max_days' => 'integer'`:

```php
    protected $casts = [
        // ... existing casts ...
        'reversal_max_days' => 'integer',
        'regular_savings_interest_enabled' => 'boolean',   // ← add this
    ];
```

Add to the `firstOrCreate` defaults array in `current()` after `'reversal_max_days' => 0`:

```php
    public static function current(): self
    {
        return self::firstOrCreate([], [
            // ... existing defaults ...
            'reversal_max_days' => 0,
            'regular_savings_interest_enabled' => false,   // ← add this
        ]);
    }
```

- [ ] **Step 6: Update `TenantTestCase::tenantSchemaIsCurrent()` to detect new columns**

In `tests/TenantTestCase.php`, add two checks at the end of `tenantSchemaIsCurrent()` before the final `return true`:

```php
    protected function tenantSchemaIsCurrent(): bool
    {
        // ... existing checks ...
        if (! $schema->hasColumn('savings_products', 'interest_enabled')) {
            return false;
        }
        if (! $schema->hasColumn('onboarding_settings', 'regular_savings_interest_enabled')) {
            return false;
        }

        return true;
    }
```

- [ ] **Step 7: Lint**

```bash
composer test:lint
```

Expected: no failures in the modified model files.

- [ ] **Step 8: Commit**

```bash
git add \
  database/migrations/tenant/2026_04_28_000005_add_interest_enabled_to_savings_products.php \
  database/migrations/tenant/2026_04_28_000006_add_regular_savings_interest_enabled_to_onboarding_settings.php \
  app/Tenant/Modules/Savings/Models/SavingsProduct.php \
  app/Tenant/Modules/Settings/Models/OnboardingSettings.php \
  tests/TenantTestCase.php
git commit -m "feat: add interest_enabled and regular_savings_interest_enabled columns (#10)"
```

---

## Task 2 — Write Failing Tests

**Files:**
- Create: `tests/Feature/Accounting/RegularSavingsInterestGlTest.php`

**Context:**
- `TenantTestCase` wraps every test in a rolled-back DB transaction — no manual cleanup needed.
- `SavingsInterestPosting` has a unique constraint named `uq_posting_period` on `(savings_account_id, period_start, period_end)`. Duplicate calls throw `UniqueConstraintViolationException`.
- The `payout_type` column is an enum `['at_maturity', 'periodic_payout', 'compound']`. Use `'compound'` for regular savings (interest credited directly to the same account balance).
- `OnboardingSettings::current()` uses `firstOrCreate([])` — seed it with `update()` to set toggles in tests.
- `ChartOfAccount` needs `normal_balance`, `level`, `is_control`, `is_postable` columns (see `LoanDisbursementGlTest` for an example).

- [ ] **Step 1: Create the test file**

Create `tests/Feature/Accounting/RegularSavingsInterestGlTest.php`:

```php
<?php

namespace Tests\Feature\Accounting;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Models\SubLedger;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsInterestPosting;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Services\RegularSavingsInterestService;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\TenantTestCase;

class RegularSavingsInterestGlTest extends TenantTestCase
{
    private RegularSavingsInterestService $service;
    private SavingsProduct $product;
    private ChartOfAccount $expenseAccount;
    private ChartOfAccount $payableAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RegularSavingsInterestService::class);

        $this->expenseAccount = ChartOfAccount::create([
            'gl_code'          => '5200',
            'name'             => 'Savings Interest Expense',
            'account_type'     => 'EXPENSE',
            'account_subtype'  => 'Interest Expense',
            'normal_balance'   => 'DR',
            'level'            => 3,
            'is_control'       => false,
            'is_postable'      => true,
        ]);

        $this->payableAccount = ChartOfAccount::create([
            'gl_code'          => '2120',
            'name'             => 'Savings Interest Payable',
            'account_type'     => 'LIABILITY',
            'account_subtype'  => 'Payables',
            'normal_balance'   => 'CR',
            'level'            => 3,
            'is_control'       => false,
            'is_postable'      => true,
        ]);

        $this->product = SavingsProduct::create([
            'code'                        => 'SAV01',
            'name'                        => 'Standard Savings',
            'type'                        => 'standard',
            'status'                      => 'active',
            'interest_rate'               => 0.1200,
            'interest_enabled'            => true,
            'interest_expense_account_id' => $this->expenseAccount->id,
            'interest_payable_account_id' => $this->payableAccount->id,
        ]);

        OnboardingSettings::current()->update(['regular_savings_interest_enabled' => true]);
    }

    public function test_interest_is_posted_when_product_interest_enabled(): void
    {
        $account = SavingsAccount::create([
            'savings_product_id' => $this->product->id,
            'account_no'         => 'SAV-001',
            'code'               => 'SAV001',
            'account_type'       => 'mandatory',
            'balance'            => 10000.00,
            'status'             => 'active',
            'created_at'         => now()->subDays(30),
        ]);

        $this->service->processAccount($account, 1);

        // SavingsInterestPosting created
        $posting = SavingsInterestPosting::on('tenant')
            ->where('savings_account_id', $account->id)
            ->first();
        $this->assertNotNull($posting, 'Expected a SavingsInterestPosting to be created');
        $this->assertGreaterThan(0, (float) $posting->interest_amount);

        // JournalEntry created and balanced
        $this->assertNotNull($posting->journal_entry_id);
        $je = JournalEntry::on('tenant')->find($posting->journal_entry_id);
        $this->assertNotNull($je);
        $lines = JournalEntryLine::on('tenant')->where('journal_entry_id', $je->id)->get();
        $totalDebit  = $lines->sum('debit');
        $totalCredit = $lines->sum('credit');
        $this->assertEquals(
            number_format((float) $totalDebit, 2),
            number_format((float) $totalCredit, 2),
            'Journal entry must balance: debits = credits'
        );

        // SubLedger entries present
        $subLedgerEntries = SubLedger::on('tenant')
            ->where('journal_entry_id', $je->id)
            ->get();
        $this->assertGreaterThan(0, $subLedgerEntries->count(), 'Expected SubLedger entries');

        // account.balance incremented
        $account->refresh();
        $this->assertGreaterThan(10000.00, (float) $account->balance, 'Balance should have increased by interest');

        // last_interest_posted_at updated
        $this->assertNotNull($account->last_interest_posted_at);
    }

    public function test_skips_account_when_global_setting_disabled(): void
    {
        OnboardingSettings::current()->update(['regular_savings_interest_enabled' => false]);

        $account = SavingsAccount::create([
            'savings_product_id' => $this->product->id,
            'account_no'         => 'SAV-002',
            'code'               => 'SAV002',
            'account_type'       => 'voluntary',
            'balance'            => 5000.00,
            'status'             => 'active',
            'created_at'         => now()->subDays(30),
        ]);

        $summary = $this->service->runBatchPosting(1);

        $this->assertEquals(0, $summary['posted']);
        $this->assertEquals(0, SavingsInterestPosting::on('tenant')->count());
    }

    public function test_skips_account_when_product_interest_disabled(): void
    {
        $this->product->update(['interest_enabled' => false]);

        $account = SavingsAccount::create([
            'savings_product_id' => $this->product->id,
            'account_no'         => 'SAV-003',
            'code'               => 'SAV003',
            'account_type'       => 'voluntary',
            'balance'            => 5000.00,
            'status'             => 'active',
            'created_at'         => now()->subDays(30),
        ]);

        $summary = $this->service->runBatchPosting(1);

        $this->assertEquals(0, $summary['posted']);
        $this->assertEquals(0, SavingsInterestPosting::on('tenant')->count());
    }

    public function test_skips_fixed_deposit_accounts(): void
    {
        $fdProduct = SavingsProduct::create([
            'code'   => 'FD01',
            'name'   => 'Fixed Deposit',
            'type'   => 'fixed',
            'status' => 'active',
        ]);

        $fdAccount = SavingsAccount::create([
            'savings_product_id' => $fdProduct->id,
            'account_no'         => 'FD-001',
            'code'               => 'FD001',
            'account_type'       => 'fixed',
            'balance'            => 10000.00,
            'status'             => 'active',
            'created_at'         => now()->subDays(30),
        ]);

        $this->service->processAccount($fdAccount, 1);

        $this->assertEquals(0, SavingsInterestPosting::on('tenant')->count());
    }

    public function test_does_not_double_post_same_period(): void
    {
        $account = SavingsAccount::create([
            'savings_product_id' => $this->product->id,
            'account_no'         => 'SAV-005',
            'code'               => 'SAV005',
            'account_type'       => 'mandatory',
            'balance'            => 10000.00,
            'status'             => 'active',
            'created_at'         => now()->subDays(30),
        ]);

        $this->service->processAccount($account, 1);
        $this->service->processAccount($account, 1);

        $this->assertEquals(
            1,
            SavingsInterestPosting::on('tenant')
                ->where('savings_account_id', $account->id)
                ->count(),
            'Only one posting should exist even after two processAccount calls'
        );
    }
}
```

- [ ] **Step 2: Run tests — confirm they fail because the service does not exist yet**

```bash
php artisan test --filter=RegularSavingsInterestGlTest
```

Expected: all 5 tests fail with `Class "App\Tenant\Modules\Savings\Services\RegularSavingsInterestService" not found` or similar.

- [ ] **Step 3: Commit the failing tests**

```bash
git add tests/Feature/Accounting/RegularSavingsInterestGlTest.php
git commit -m "test(wip): add failing tests for RegularSavingsInterestService (#10)"
```

---

## Task 3 — Implement `RegularSavingsInterestService`

**Files:**
- Create: `app/Tenant/Modules/Savings/Services/RegularSavingsInterestService.php`

**Context:**
- `processAccount()` guards: account_type in `['mandatory','voluntary']`, status `active`, `product.interest_enabled = true`, `interest_rate > 0`, `interest_expense_account_id` set, `interest_payable_account_id` set.
- Period: `period_start = last_interest_posted_at ?? created_at`, `period_end = today`.
- Calculation: `FixedDepositCalculator::calculateInterest(balance, rate, from, to)` — already handles `days <= 0` by returning `0.0`.
- GL posting: `SavingsJournalService::postInterest($account, $expenseAccount, $creditAccount, $interest, $periodStart, $periodEnd, $actorId)`.
- After posting: `account->increment('balance', $interest)`, `account->update(['last_interest_posted_at' => now()])`.
- Idempotency: `SavingsInterestPosting` unique constraint on `(savings_account_id, period_start, period_end)` — catch `UniqueConstraintViolationException` in `runBatchPosting()` and count as `skipped`.
- `runBatchPosting()` first checks `OnboardingSettings::current()->regular_savings_interest_enabled`; returns early if false.
- `payout_type` value for `SavingsInterestPosting`: use `'compound'` (closest match for "interest credited directly to same account balance").

- [ ] **Step 1: Create the service**

Create `app/Tenant/Modules/Savings/Services/RegularSavingsInterestService.php`:

```php
<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsInterestPosting;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegularSavingsInterestService
{
    public function __construct(
        protected FixedDepositCalculator $calculator,
        protected SavingsJournalService $journal,
    ) {}

    public function processAccount(SavingsAccount $account, int $actorId): void
    {
        if (! in_array($account->account_type, ['mandatory', 'voluntary'], true)) {
            return;
        }
        if ($account->status !== 'active') {
            return;
        }

        $account->loadMissing('savingsProduct');
        $product = $account->savingsProduct;

        if (! $product?->interest_enabled) {
            return;
        }
        if (! ((float) ($product->interest_rate ?? 0) > 0)) {
            return;
        }
        if (! $product->interest_expense_account_id || ! $product->interest_payable_account_id) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($account, $product, $actorId) {
            $account = SavingsAccount::on('tenant')->lockForUpdate()->findOrFail($account->id);

            $periodStart = Carbon::parse($account->last_interest_posted_at ?? $account->created_at);
            $periodEnd   = Carbon::today();
            $interest    = $this->calculator->calculateInterest(
                (float) $account->balance,
                (float) $product->interest_rate,
                $periodStart,
                $periodEnd,
            );

            if ($interest <= 0) {
                return;
            }

            $expenseAccount = ChartOfAccount::on('tenant')->findOrFail($product->interest_expense_account_id);
            $payableAccount = ChartOfAccount::on('tenant')->findOrFail($product->interest_payable_account_id);

            $je = $this->journal->postInterest(
                $account,
                $expenseAccount,
                $payableAccount,
                $interest,
                $periodStart->toDateString(),
                $periodEnd->toDateString(),
                $actorId,
            );

            SavingsInterestPosting::on('tenant')->create([
                'savings_account_id' => $account->id,
                'period_start'       => $periodStart->toDateString(),
                'period_end'         => $periodEnd->toDateString(),
                'principal'          => $account->balance,
                'rate'               => $product->interest_rate,
                'interest_amount'    => $interest,
                'payout_type'        => 'compound',
                'journal_entry_id'   => $je?->id,
                'posted_by'          => $actorId,
                'created_at'         => now(),
            ]);

            $account->increment('balance', $interest);
            $account->update(['last_interest_posted_at' => now()]);
        });
    }

    public function runBatchPosting(int $actorId): array
    {
        $summary = ['posted' => 0, 'skipped' => 0, 'errors' => []];

        if (! OnboardingSettings::current()->regular_savings_interest_enabled) {
            return $summary;
        }

        SavingsAccount::on('tenant')
            ->whereIn('account_type', ['mandatory', 'voluntary'])
            ->where('status', 'active')
            ->whereHas('savingsProduct', fn ($q) => $q->where('interest_enabled', true))
            ->with('savingsProduct')
            ->each(function (SavingsAccount $account) use ($actorId, &$summary) {
                try {
                    $this->processAccount($account, $actorId);
                    $summary['posted']++;
                } catch (UniqueConstraintViolationException) {
                    $summary['skipped']++;
                } catch (\Throwable $e) {
                    $summary['errors'][] = [
                        'account_no' => $account->account_no,
                        'reason'     => $e->getMessage(),
                    ];
                    Log::error("Regular savings interest error on {$account->account_no}: ".$e->getMessage());
                }
            });

        return $summary;
    }
}
```

- [ ] **Step 2: Run tests — all 5 should pass**

```bash
php artisan test --filter=RegularSavingsInterestGlTest
```

Expected:
```
PASS  Tests\Feature\Accounting\RegularSavingsInterestGlTest
✓ test_interest_is_posted_when_product_interest_enabled
✓ test_skips_account_when_global_setting_disabled
✓ test_skips_account_when_product_interest_disabled
✓ test_skips_fixed_deposit_accounts
✓ test_does_not_double_post_same_period
```

If any test fails, read the error carefully before changing anything — most failures at this stage will be assertion mismatches or missing test setup data, not bugs in the service.

- [ ] **Step 3: Lint**

```bash
composer test:lint
```

Expected: no failures.

- [ ] **Step 4: Commit**

```bash
git add app/Tenant/Modules/Savings/Services/RegularSavingsInterestService.php
git commit -m "feat: implement RegularSavingsInterestService (Issue #10)"
```

---

## Task 4 — Controller and Route

**Files:**
- Create: `app/Tenant/Http/Controllers/Api/V1/RegularSavingsInterestController.php`
- Modify: `routes/tenant_api.php`

**Context:** No request body — actor resolved from `auth()->id()`. Response mirrors `FixedDepositController::postInterest()` but uses `'summary'` key per the spec (instead of `'data'`).

- [ ] **Step 1: Create the controller**

Create `app/Tenant/Http/Controllers/Api/V1/RegularSavingsInterestController.php`:

```php
<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Savings\Services\RegularSavingsInterestService;
use Illuminate\Http\JsonResponse;

class RegularSavingsInterestController extends Controller
{
    public function __construct(
        protected RegularSavingsInterestService $service,
    ) {}

    public function postInterest(): JsonResponse
    {
        $actorId = auth()->id() ?? 1;
        $summary = $this->service->runBatchPosting($actorId);

        return response()->json([
            'message' => 'Interest posting complete',
            'summary' => $summary,
        ]);
    }
}
```

- [ ] **Step 2: Register the route in `routes/tenant_api.php`**

Find the existing FD interest route:
```php
Route::post('savings/fixed-deposits/post-interest', [FixedDepositController::class, 'postInterest']);
```

Add the regular savings route directly after it:
```php
Route::post('savings/fixed-deposits/post-interest', [FixedDepositController::class, 'postInterest']);
Route::post('savings/regular-interest/post-interest', [RegularSavingsInterestController::class, 'postInterest']);
```

Add the import at the top of the file alongside the other controller imports:
```php
use App\Tenant\Http\Controllers\Api\V1\RegularSavingsInterestController;
```

- [ ] **Step 3: Verify the route is registered**

```bash
php artisan route:list | grep regular-interest
```

Expected:
```
POST   api/v1/tenant/savings/regular-interest/post-interest  ...RegularSavingsInterestController@postInterest
```

- [ ] **Step 4: Lint**

```bash
composer test:lint
```

Expected: no failures.

- [ ] **Step 5: Commit**

```bash
git add \
  app/Tenant/Http/Controllers/Api/V1/RegularSavingsInterestController.php \
  routes/tenant_api.php
git commit -m "feat: expose RegularSavingsInterestController POST endpoint (Issue #10)"
```

---

## Task 5 — Artisan Command

**Files:**
- Create: `app/Console/Commands/PostRegularSavingsInterest.php`

**Context:** Mirrors `CheckLoanArrearsCommand` exactly — loops active tenants, switches DB with `DatabaseSwitcher`, resolves service fresh from container per tenant, calls `runBatchPosting(actorId: 1)` (system actor = 1 avoids any FK constraint on `posted_by`). Laravel 12 auto-discovers commands in `app/Console/Commands/` — no registration needed. Exits with code `1` if any errors were collected so cron monitors can alert.

- [ ] **Step 1: Create the command**

Create `app/Console/Commands/PostRegularSavingsInterest.php`:

```php
<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Savings\Services\RegularSavingsInterestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PostRegularSavingsInterest extends Command
{
    protected $signature = 'savings:post-regular-interest
                            {--tenant= : Run for a specific tenant subdomain only}';

    protected $description = 'Post interest for voluntary and mandatory savings accounts across all active tenants.';

    public function __construct(protected DatabaseSwitcher $switcher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $subdomain = $this->option('tenant');
        $query     = Tenant::where('status', 'active');

        if ($subdomain) {
            $query->where('subdomain', $subdomain);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');
            return self::SUCCESS;
        }

        $totalPosted  = 0;
        $totalSkipped = 0;
        $hasErrors    = false;

        foreach ($tenants as $tenant) {
            try {
                $this->switcher->switch($tenant);

                /** @var RegularSavingsInterestService $service */
                $service = app(RegularSavingsInterestService::class);
                $result  = $service->runBatchPosting(1);

                $totalPosted  += $result['posted'];
                $totalSkipped += $result['skipped'];

                if (! empty($result['errors'])) {
                    $hasErrors = true;
                    foreach ($result['errors'] as $err) {
                        $this->warn("  [{$tenant->subdomain}] {$err['account_no']}: {$err['reason']}");
                    }
                } else {
                    $this->line("  [{$tenant->subdomain}] posted={$result['posted']} skipped={$result['skipped']}");
                }

                Log::info("savings:post-regular-interest [{$tenant->subdomain}]", $result);
            } catch (\Throwable $e) {
                $hasErrors = true;
                $this->error("  [{$tenant->subdomain}] failed: {$e->getMessage()}");
                Log::error("savings:post-regular-interest [{$tenant->subdomain}] failed: ".$e->getMessage());
            }
        }

        $this->info("Done. posted={$totalPosted} skipped={$totalSkipped}");

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
```

- [ ] **Step 2: Verify the command is auto-discovered**

```bash
php artisan list | grep savings
```

Expected output includes:
```
savings:post-regular-interest  Post interest for voluntary and mandatory savings accounts across all active tenants.
```

- [ ] **Step 3: Lint**

```bash
composer test:lint
```

Expected: no failures.

- [ ] **Step 4: Run the full test suite to check for regressions**

```bash
php artisan test --testsuite=Tenant
```

Expected: all previously-passing tests still pass; our 5 new tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/PostRegularSavingsInterest.php
git commit -m "feat: add savings:post-regular-interest artisan command (Issue #10)"
```

---

## Self-Review Checklist

- [x] All 9 files from spec's file map are covered
- [x] `interest_enabled` migration uses `Schema::connection('tenant')` — matches pattern in existing migrations
- [x] `OnboardingSettings::current()` defaults include `regular_savings_interest_enabled => false`
- [x] `processAccount()` checks all 6 guards from the spec before entering the DB transaction
- [x] `runBatchPosting()` checks global toggle first — returns early if disabled
- [x] Idempotency: `UniqueConstraintViolationException` caught in `runBatchPosting()`, counted as `skipped`
- [x] Journal entry: DR expense account, CR payable account — matches spec DR/CR table
- [x] `account.balance` incremented after GL posting, not before
- [x] `last_interest_posted_at` updated after posting
- [x] API response uses `'summary'` key per spec (not `'data'`)
- [x] Artisan command exits with `FAILURE` (code 1) when errors present — safe for cron monitoring
- [x] `TenantTestCase::tenantSchemaIsCurrent()` updated so new migrations run before tests
- [x] Test `payout_type` = `'compound'` — valid enum value in `savings_interest_postings`
