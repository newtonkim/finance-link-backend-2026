# Fixed Deposits — Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the full fixed deposit backend — migrations, models, calculation engine, maturity actions, GL posting, controller, and routes — so the frontend can open FDs, view them, and trigger interest posting without any cron job.

**Architecture:** Event-driven with deferred posting. `FixedDepositInterestService::processAccount()` is the single entry point called by (a) `SavingsAccountController::show()` on every FD account view, and (b) `FixedDepositController::postInterest()` for the manager month-end sweep. A `UNIQUE KEY` on `savings_interest_postings(savings_account_id, period_start, period_end)` prevents double-posting under any concurrency scenario.

**Tech Stack:** Laravel 12, PHP 8.2, MySQL (tenant DB), existing `SavingsJournalService` GL posting pattern, Pest tests.

**IMPORTANT — read before every task:**
- Read `CLAUDE.md` at the repo root before starting
- No method may exceed 200 lines; no PHP class file may exceed 400 lines
- Follow the layering: Model → Service → Controller (never business logic in controllers)
- All tenant DB queries use `Model::on('tenant')` or the `'tenant'` connection

---

## File Map

| Action | File | Responsibility |
|---|---|---|
| Create | `database/migrations/tenant/2026_04_19_000001_add_fd_fields_to_savings_products.php` | New FD columns on savings_products |
| Create | `database/migrations/tenant/2026_04_19_000002_add_fd_fields_to_savings_accounts.php` | New FD columns on savings_accounts |
| Create | `database/migrations/tenant/2026_04_19_000003_create_savings_interest_postings_table.php` | Audit log for every interest posting |
| Create | `app/Tenant/Modules/Savings/Models/SavingsInterestPosting.php` | Eloquent model for the audit table |
| Modify | `app/Tenant/Modules/Savings/Models/SavingsProduct.php` | Add fillable, casts, relations for FD fields |
| Modify | `app/Tenant/Modules/Savings/Models/SavingsAccount.php` | Add fillable, casts, relations for FD fields |
| Modify | `app/Http/Requests/Tenant/SavingsProductFormRequest.php` | FD-specific validation rules |
| Create | `app/Tenant/Modules/Savings/Services/FixedDepositCalculator.php` | Pure interest math — no DB access |
| Modify | `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` | Add `postInterest()` method |
| Create | `app/Tenant/Modules/Savings/Services/FixedDepositMaturityService.php` | Rollover / convert / manual maturity actions |
| Create | `app/Tenant/Modules/Savings/Services/FixedDepositInterestService.php` | `processAccount()` + `runMonthEndSweep()` |
| Modify | `app/Tenant/Modules/Savings/Services/SavingsAccountService.php` | Compute FD dates on `create()` |
| Create | `app/Tenant/Http/Controllers/Api/V1/FixedDepositController.php` | list, postInterest, processMaturity, interestPostings |
| Modify | `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php` | Call `processAccount()` in `show()` |
| Modify | `routes/tenant_api.php` | Register 4 new FD routes |
| Create | `tests/Feature/Savings/FixedDepositInterestTest.php` | Feature tests |

---

### Task 1: Three Migrations

**Files:**
- Create: `database/migrations/tenant/2026_04_19_000001_add_fd_fields_to_savings_products.php`
- Create: `database/migrations/tenant/2026_04_19_000002_add_fd_fields_to_savings_accounts.php`
- Create: `database/migrations/tenant/2026_04_19_000003_create_savings_interest_postings_table.php`

- [ ] **Step 1: Create migration 1 — FD fields on savings_products**

```php
<?php
// database/migrations/tenant/2026_04_19_000001_add_fd_fields_to_savings_products.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('savings_products', function (Blueprint $table) {
            $table->decimal('interest_rate', 5, 4)->default(0)->after('status')
                  ->comment('Annual rate e.g. 0.1200 = 12%');
            $table->enum('interest_payout_type', ['at_maturity', 'periodic_payout', 'compound'])
                  ->default('at_maturity')->after('interest_rate');
            $table->enum('interest_posting_frequency', ['monthly', 'quarterly', 'semi_annually', 'annually'])
                  ->default('monthly')->after('interest_payout_type');
            $table->unsignedInteger('default_tenor_months')->default(6)->after('interest_posting_frequency');
            $table->enum('maturity_action', ['auto_rollover', 'manual', 'convert_to_savings'])
                  ->default('manual')->after('default_tenor_months');
            $table->unsignedBigInteger('convert_to_product_id')->nullable()->after('maturity_action');
            $table->unsignedBigInteger('interest_expense_account_id')->nullable()->after('convert_to_product_id');
            $table->unsignedBigInteger('interest_payable_account_id')->nullable()->after('interest_expense_account_id');

            $table->foreign('convert_to_product_id')->references('id')->on('savings_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('savings_products', function (Blueprint $table) {
            $table->dropForeign(['convert_to_product_id']);
            $table->dropColumn([
                'interest_rate', 'interest_payout_type', 'interest_posting_frequency',
                'default_tenor_months', 'maturity_action', 'convert_to_product_id',
                'interest_expense_account_id', 'interest_payable_account_id',
            ]);
        });
    }
};
```

- [ ] **Step 2: Create migration 2 — FD fields on savings_accounts**

```php
<?php
// database/migrations/tenant/2026_04_19_000002_add_fd_fields_to_savings_accounts.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('savings_accounts', function (Blueprint $table) {
            $table->unsignedInteger('tenor_months')->nullable()->after('status');
            $table->date('maturity_date')->nullable()->after('tenor_months')->index();
            $table->date('next_interest_date')->nullable()->after('maturity_date')->index();
            $table->enum('maturity_action', ['auto_rollover', 'manual', 'convert_to_savings'])
                  ->nullable()->after('next_interest_date');
            $table->unsignedBigInteger('payout_savings_account_id')->nullable()->after('maturity_action');
            $table->timestamp('last_interest_posted_at')->nullable()->after('payout_savings_account_id');

            $table->foreign('payout_savings_account_id')->references('id')->on('savings_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('savings_accounts', function (Blueprint $table) {
            $table->dropForeign(['payout_savings_account_id']);
            $table->dropColumn([
                'tenor_months', 'maturity_date', 'next_interest_date',
                'maturity_action', 'payout_savings_account_id', 'last_interest_posted_at',
            ]);
        });
    }
};
```

- [ ] **Step 3: Create migration 3 — savings_interest_postings table**

```php
<?php
// database/migrations/tenant/2026_04_19_000003_create_savings_interest_postings_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('savings_interest_postings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('savings_account_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('principal', 15, 2);
            $table->decimal('rate', 5, 4);
            $table->decimal('interest_amount', 15, 2);
            $table->enum('payout_type', ['at_maturity', 'periodic_payout', 'compound']);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('posted_by')->nullable()->comment('null = system auto-post');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['savings_account_id', 'period_start', 'period_end'], 'uq_posting_period');
            $table->foreign('savings_account_id')->references('id')->on('savings_accounts');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('savings_interest_postings');
    }
};
```

- [ ] **Step 4: Run migrations to verify they apply cleanly**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
php artisan tenants:migrate
```

Expected: No errors. New columns visible in `savings_products`, `savings_accounts`, and new `savings_interest_postings` table.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/tenant/2026_04_19_000001_add_fd_fields_to_savings_products.php
git add database/migrations/tenant/2026_04_19_000002_add_fd_fields_to_savings_accounts.php
git add database/migrations/tenant/2026_04_19_000003_create_savings_interest_postings_table.php
git commit -m "feat(savings): add fixed deposit schema migrations"
```

---

### Task 2: Models

**Files:**
- Create: `app/Tenant/Modules/Savings/Models/SavingsInterestPosting.php`
- Modify: `app/Tenant/Modules/Savings/Models/SavingsProduct.php`
- Modify: `app/Tenant/Modules/Savings/Models/SavingsAccount.php`

- [ ] **Step 1: Create SavingsInterestPosting model**

```php
<?php
// app/Tenant/Modules/Savings/Models/SavingsInterestPosting.php
namespace App\Tenant\Modules\Savings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsInterestPosting extends Model
{
    protected $connection = 'tenant';
    public $timestamps = false;

    protected $fillable = [
        'savings_account_id',
        'period_start',
        'period_end',
        'principal',
        'rate',
        'interest_amount',
        'payout_type',
        'journal_entry_id',
        'posted_by',
        'created_at',
    ];

    protected $casts = [
        'period_start'    => 'date',
        'period_end'      => 'date',
        'principal'       => 'decimal:2',
        'rate'            => 'decimal:4',
        'interest_amount' => 'decimal:2',
        'created_at'      => 'datetime',
    ];

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
    }
}
```

- [ ] **Step 2: Update SavingsProduct model — replace entire file**

```php
<?php
// app/Tenant/Modules/Savings/Models/SavingsProduct.php
namespace App\Tenant\Modules\Savings\Models;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SavingsProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    protected $fillable = [
        'code', 'name', 'type',
        'minimum_balance', 'minimum_maturity_months', 'dormancy_period_months',
        'charge_on_deposit', 'charge_on_withdraw', 'charge_on_transfer',
        'status',
        'monthly_fee_enabled', 'monthly_fee_type', 'monthly_fee_amount', 'monthly_fee_deduction_day',
        // FD fields
        'interest_rate', 'interest_payout_type', 'interest_posting_frequency',
        'default_tenor_months', 'maturity_action',
        'convert_to_product_id', 'interest_expense_account_id', 'interest_payable_account_id',
    ];

    protected $casts = [
        'minimum_balance'          => 'decimal:2',
        'minimum_maturity_months'  => 'integer',
        'dormancy_period_months'   => 'integer',
        'charge_on_deposit'        => 'boolean',
        'charge_on_withdraw'       => 'boolean',
        'charge_on_transfer'       => 'boolean',
        'monthly_fee_enabled'      => 'boolean',
        'monthly_fee_amount'       => 'decimal:2',
        'monthly_fee_deduction_day'=> 'integer',
        // FD casts
        'interest_rate'            => 'decimal:4',
        'default_tenor_months'     => 'integer',
        'convert_to_product_id'    => 'integer',
        'interest_expense_account_id' => 'integer',
        'interest_payable_account_id' => 'integer',
    ];

    public function charges(): HasMany
    {
        return $this->hasMany(SavingsProductCharge::class);
    }

    public function convertToProduct(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class, 'convert_to_product_id');
    }

    public function interestExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'interest_expense_account_id');
    }

    public function interestPayableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'interest_payable_account_id');
    }

    public function isFixed(): bool
    {
        return $this->type === 'fixed';
    }
}
```

- [ ] **Step 3: Update SavingsAccount model — add FD fillable, casts, relations**

Add the following to `$fillable` array in `SavingsAccount.php`:

```php
// Add to $fillable:
'tenor_months',
'maturity_date',
'next_interest_date',
'maturity_action',
'payout_savings_account_id',
'last_interest_posted_at',
```

Add to `$casts`:

```php
'tenor_months'             => 'integer',
'maturity_date'            => 'date',
'next_interest_date'       => 'date',
'payout_savings_account_id'=> 'integer',
'last_interest_posted_at'  => 'datetime',
```

Add relations (after the existing `savingsProduct()` relation):

```php
public function payoutSavingsAccount(): BelongsTo
{
    return $this->belongsTo(SavingsAccount::class, 'payout_savings_account_id');
}

public function interestPostings(): HasMany
{
    return $this->hasMany(SavingsInterestPosting::class);
}

public function isFixed(): bool
{
    return $this->account_type === 'fixed';
}
```

Add to imports at top of file: `use Illuminate\Database\Eloquent\Relations\HasMany;`

- [ ] **Step 4: Verify PHP syntax on all three files**

```bash
php -l app/Tenant/Modules/Savings/Models/SavingsInterestPosting.php
php -l app/Tenant/Modules/Savings/Models/SavingsProduct.php
php -l app/Tenant/Modules/Savings/Models/SavingsAccount.php
```

Expected: `No syntax errors detected` for each.

- [ ] **Step 5: Commit**

```bash
git add app/Tenant/Modules/Savings/Models/
git commit -m "feat(savings): add SavingsInterestPosting model and extend FD model fields"
```

---

### Task 3: Extend SavingsProductFormRequest

**Files:**
- Modify: `app/Http/Requests/Tenant/SavingsProductFormRequest.php`

- [ ] **Step 1: Replace the rules() method with FD-aware validation**

```php
public function rules(): array
{
    $isFixed = $this->input('type') === 'fixed';

    return [
        'name'                    => ['required', 'string', 'max:255'],
        'type'                    => ['required', 'in:fixed,standard'],
        'minimum_balance'         => ['required', 'numeric', 'min:0'],
        'minimum_maturity_months' => ['required', 'integer', 'min:0'],
        'dormancy_period_months'  => ['required', 'integer', 'min:0'],
        'charge_on_deposit'       => ['boolean'],
        'charge_on_withdraw'      => ['boolean'],
        'charge_on_transfer'      => ['boolean'],
        'status'                  => ['in:active,inactive'],
        'monthly_fee_enabled'     => ['boolean'],
        'monthly_fee_type'        => ['nullable', 'in:percentage,amount'],
        'monthly_fee_amount'      => ['nullable', 'numeric', 'min:0'],
        'monthly_fee_deduction_day' => ['nullable', 'integer', 'min:1', 'max:31'],
        'charges'                 => ['nullable', 'array'],
        'charges.*.name'          => ['nullable', 'string', 'max:255'],
        'charges.*.type'          => ['required', 'in:deposit,withdraw,transfer'],
        'charges.*.minimum_amount'=> ['required', 'numeric', 'min:0'],
        'charges.*.maximum_amount'=> ['nullable', 'numeric', 'min:0'],
        'charges.*.charge_type'   => ['required', 'in:percentage,amount'],
        'charges.*.amount'        => ['required', 'numeric', 'min:0'],
        'charges.*.is_reversible' => ['boolean'],
        // FD-only fields
        'interest_rate'                => [$isFixed ? 'required' : 'nullable', 'numeric', 'min:0', 'max:100'],
        'interest_payout_type'         => [$isFixed ? 'required' : 'nullable', 'in:at_maturity,periodic_payout,compound'],
        'interest_posting_frequency'   => ['nullable', 'in:monthly,quarterly,semi_annually,annually'],
        'default_tenor_months'         => [$isFixed ? 'required' : 'nullable', 'integer', 'min:1'],
        'maturity_action'              => [$isFixed ? 'required' : 'nullable', 'in:auto_rollover,manual,convert_to_savings'],
        'convert_to_product_id'        => ['nullable', 'integer', 'exists:savings_products,id'],
        'interest_expense_account_id'  => ['nullable', 'integer'],
        'interest_payable_account_id'  => ['nullable', 'integer'],
    ];
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l app/Http/Requests/Tenant/SavingsProductFormRequest.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Requests/Tenant/SavingsProductFormRequest.php
git commit -m "feat(savings): add FD validation rules to SavingsProductFormRequest"
```

---

### Task 4: FixedDepositCalculator — Pure Interest Math

**Files:**
- Create: `app/Tenant/Modules/Savings/Services/FixedDepositCalculator.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Savings/FixedDepositInterestTest.php
namespace Tests\Feature\Savings;

use App\Tenant\Modules\Savings\Services\FixedDepositCalculator;
use Carbon\Carbon;
use Tests\TestCase;

class FixedDepositInterestTest extends TestCase
{
    public function test_calculator_computes_simple_interest_correctly(): void
    {
        $calc = new FixedDepositCalculator;

        // 12% p.a. on 100,000 for 30 days
        $interest = $calc->calculateInterest(
            principal: 100_000.00,
            annualRate: 0.12,
            from: Carbon::parse('2026-01-01'),
            to: Carbon::parse('2026-01-31'),
        );

        // 100000 × 0.12 × (30/365) = 986.30
        $this->assertEqualsWithDelta(986.30, $interest, 0.02);
    }

    public function test_next_interest_date_monthly(): void
    {
        $calc = new FixedDepositCalculator;
        $next = $calc->nextInterestDate(Carbon::parse('2026-01-15'), 'monthly');
        $this->assertEquals('2026-02-15', $next->toDateString());
    }

    public function test_next_interest_date_quarterly(): void
    {
        $calc = new FixedDepositCalculator;
        $next = $calc->nextInterestDate(Carbon::parse('2026-01-15'), 'quarterly');
        $this->assertEquals('2026-04-15', $next->toDateString());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=test_calculator_computes_simple_interest_correctly
```

Expected: FAIL — class not found.

- [ ] **Step 3: Create FixedDepositCalculator**

```php
<?php
// app/Tenant/Modules/Savings/Services/FixedDepositCalculator.php
namespace App\Tenant\Modules\Savings\Services;

use Carbon\Carbon;

class FixedDepositCalculator
{
    /**
     * Simple interest: principal × annual_rate × (days / 365)
     * Used for at_maturity and periodic_payout types.
     * For compound, pass the current running balance as $principal.
     */
    public function calculateInterest(
        float $principal,
        float $annualRate,
        Carbon $from,
        Carbon $to,
    ): float {
        $days = (int) $from->diffInDays($to);

        if ($days <= 0 || $principal <= 0 || $annualRate <= 0) {
            return 0.0;
        }

        return round($principal * $annualRate * ($days / 365), 2);
    }

    /**
     * Advance a date by one posting frequency period.
     */
    public function nextInterestDate(Carbon $from, string $frequency): Carbon
    {
        return match ($frequency) {
            'quarterly'     => $from->copy()->addMonths(3),
            'semi_annually' => $from->copy()->addMonths(6),
            'annually'      => $from->copy()->addYear(),
            default         => $from->copy()->addMonth(), // monthly
        };
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --filter=FixedDepositInterestTest
```

Expected: 3 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Tenant/Modules/Savings/Services/FixedDepositCalculator.php
git add tests/Feature/Savings/FixedDepositInterestTest.php
git commit -m "feat(savings): add FixedDepositCalculator with interest formula and frequency helpers"
```

---

### Task 5: Add postInterest() to SavingsJournalService

**Files:**
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php`

- [ ] **Step 1: Add the postInterest() public method after postOpeningBalance()**

Insert this method between `postOpeningBalance()` and `postReversal()` in `SavingsJournalService`:

```php
/**
 * DR Interest Expense on Savings  CR $creditAccount (FD Liability or Payout Savings)
 *
 * @param  \App\Tenant\Modules\Accounting\Models\ChartOfAccount  $expenseAccount  product->interestExpenseAccount
 * @param  \App\Tenant\Modules\Accounting\Models\ChartOfAccount  $creditAccount   resolved by caller based on payout type
 */
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

        $je = JournalEntry::create([
            'entry_no'     => $this->nextEntryNo(),
            'date'         => $date,
            'period_date'  => $date,
            'fiscal_period'=> substr($date, 0, 7),
            'reference'    => 'INT-'.$account->account_no,
            'narration'    => $narration,
            'journal_type' => 'FD_INTEREST',
            'status'       => 'posted',
            'is_system'    => true,
            'posted_at'    => now(),
            'posted_by'    => $actorId,
        ]);

        // DR Interest Expense
        $jel1 = JournalEntryLine::create([
            'journal_entry_id' => $je->id,
            'account_id'       => $expenseAccount->id,
            'debit'            => $interestAmount,
            'credit'           => 0,
            'narration'        => $narration,
            'line_no'          => 1,
        ]);
        $jel1->setRelation('account', $expenseAccount);
        $this->postToGeneralLedger($jel1, $je, $date);

        // CR Savings Liability (FD or payout account)
        $jel2 = JournalEntryLine::create([
            'journal_entry_id' => $je->id,
            'account_id'       => $creditAccount->id,
            'debit'            => 0,
            'credit'           => $interestAmount,
            'narration'        => $narration,
            'member_id'        => $account->member_id,
            'savings_id'       => $account->id,
            'line_no'          => 2,
        ]);
        $jel2->setRelation('account', $creditAccount);
        $this->postToGeneralLedger($jel2, $je, $date);
        if ($account->member_id) {
            $this->postToSubLedger($jel2, $je, $date, $account->member_id);
        }

        return $je;
    });
}
```

Also add `ChartOfAccount` to the use statement if not already imported (it already is — line 7 of the existing file).

- [ ] **Step 2: Verify file is under 400 lines and syntax is clean**

```bash
wc -l app/Tenant/Modules/Accounting/Services/SavingsJournalService.php
php -l app/Tenant/Modules/Accounting/Services/SavingsJournalService.php
```

Expected: under 410 lines, no syntax errors.

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Accounting/Services/SavingsJournalService.php
git commit -m "feat(savings): add postInterest() to SavingsJournalService for FD interest GL entries"
```

---

### Task 6: FixedDepositMaturityService

**Files:**
- Create: `app/Tenant/Modules/Savings/Services/FixedDepositMaturityService.php`

- [ ] **Step 1: Create the service**

```php
<?php
// app/Tenant/Modules/Savings/Services/FixedDepositMaturityService.php
namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FixedDepositMaturityService
{
    public function __construct(
        protected FixedDepositCalculator $calculator,
    ) {}

    /**
     * Execute the maturity action for a fixed deposit account.
     * Called inside processAccount() after maturity interest has been posted.
     */
    public function execute(SavingsAccount $account, int $actorId): void
    {
        $effectiveAction = $account->maturity_action
            ?? $account->savingsProduct?->maturity_action
            ?? 'manual';

        match ($effectiveAction) {
            'auto_rollover'     => $this->rollover($account, $actorId),
            'convert_to_savings'=> $this->convertToSavings($account, $actorId),
            default             => $this->markMatured($account),
        };
    }

    private function rollover(SavingsAccount $account, int $actorId): void
    {
        $product = $account->savingsProduct;
        $tenor   = $account->tenor_months ?? $product?->default_tenor_months ?? 6;
        $newRate = (float) ($product?->interest_rate ?? $account->interest_rate ?? 0);
        $now     = Carbon::now();

        // Close old account
        $account->update(['status' => 'matured']);

        $payoutType = $product?->interest_payout_type ?? 'at_maturity';
        $nextInterestDate = $payoutType !== 'at_maturity'
            ? $this->calculator->nextInterestDate($now, $product?->interest_posting_frequency ?? 'monthly')
            : null;

        SavingsAccount::create([
            'member_id'               => $account->member_id,
            'savings_product_id'      => $account->savings_product_id,
            'account_no'              => $this->newAccountNo($account),
            'account_type'            => 'fixed',
            'balance'                 => $account->balance,
            'interest_rate'           => $newRate,
            'status'                  => 'active',
            'branch_id'               => $account->branch_id,
            'tenor_months'            => $tenor,
            'maturity_date'           => $now->copy()->addMonths($tenor),
            'next_interest_date'      => $nextInterestDate,
            'maturity_action'         => $account->maturity_action ?? $product?->maturity_action,
            'payout_savings_account_id' => $account->payout_savings_account_id,
            'consider_min_balance'    => $account->consider_min_balance,
        ]);

        Log::info("FD auto-rolled over: {$account->account_no} closed, new account opened.");
    }

    private function convertToSavings(SavingsAccount $account, int $actorId): void
    {
        $product = $account->savingsProduct;
        $targetProductId = $product?->convert_to_product_id;

        if (! $targetProductId) {
            Log::warning("FD convert_to_savings: no convert_to_product_id on product {$product?->name}. Falling back to manual.");
            $this->markMatured($account);
            return;
        }

        $account->update([
            'account_type'       => 'voluntary',
            'savings_product_id' => $targetProductId,
            'status'             => 'active',
            'maturity_date'      => null,
            'next_interest_date' => null,
            'tenor_months'       => null,
        ]);
    }

    private function markMatured(SavingsAccount $account): void
    {
        $account->update(['status' => 'matured']);
    }

    private function newAccountNo(SavingsAccount $account): string
    {
        $prefix = strtoupper(substr($account->account_no, 0, 3));
        $seq    = SavingsAccount::withTrashed()
            ->where('savings_product_id', $account->savings_product_id)
            ->count() + 1;

        return $prefix.'-'.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
```

- [ ] **Step 2: Verify syntax and line count**

```bash
wc -l app/Tenant/Modules/Savings/Services/FixedDepositMaturityService.php
php -l app/Tenant/Modules/Savings/Services/FixedDepositMaturityService.php
```

Expected: under 110 lines, no syntax errors.

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Savings/Services/FixedDepositMaturityService.php
git commit -m "feat(savings): add FixedDepositMaturityService for rollover, convert, and manual actions"
```

---

### Task 7: FixedDepositInterestService — Main Engine

**Files:**
- Create: `app/Tenant/Modules/Savings/Services/FixedDepositInterestService.php`

- [ ] **Step 1: Create the service**

```php
<?php
// app/Tenant/Modules/Savings/Services/FixedDepositInterestService.php
namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsInterestPosting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixedDepositInterestService
{
    public function __construct(
        protected FixedDepositCalculator $calculator,
        protected FixedDepositMaturityService $maturity,
        protected SavingsJournalService $journal,
    ) {}

    /**
     * Process one FD account: post due interest and execute maturity action.
     * Safe to call multiple times — idempotent via DB unique key.
     */
    public function processAccount(SavingsAccount $account, int $actorId): void
    {
        if (! $account->isFixed() || $account->status !== 'active') {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($account, $actorId) {
            // Re-fetch with lock to prevent concurrent double-posting
            $account = SavingsAccount::on('tenant')
                ->lockForUpdate()
                ->findOrFail($account->id);

            $today = Carbon::today();

            if ($account->maturity_date && Carbon::parse($account->maturity_date)->lte($today)) {
                $this->postMaturityInterest($account, $actorId);
                return;
            }

            if ($account->next_interest_date
                && Carbon::parse($account->next_interest_date)->lte($today)
                && in_array($account->savingsProduct?->interest_payout_type, ['periodic_payout', 'compound'], true)
            ) {
                $this->postPeriodicInterest($account, $actorId);
            }
        });
    }

    /**
     * Sweep all active FDs with overdue interest or maturity dates.
     * Returns a summary array.
     */
    public function runMonthEndSweep(int $actorId): array
    {
        $summary = ['posted' => 0, 'skipped' => 0, 'matured' => 0, 'errors' => []];

        SavingsAccount::on('tenant')
            ->where('account_type', 'fixed')
            ->where('status', 'active')
            ->where(fn ($q) =>
                $q->whereDate('next_interest_date', '<=', today())
                  ->orWhereDate('maturity_date', '<=', today())
            )
            ->each(function (SavingsAccount $account) use ($actorId, &$summary) {
                try {
                    $statusBefore = $account->status;
                    $this->processAccount($account, $actorId);
                    $account->refresh();
                    if (in_array($account->status, ['matured', 'closed'], true)) {
                        $summary['matured']++;
                    } else {
                        $summary['posted']++;
                    }
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    $summary['skipped']++;
                } catch (\Throwable $e) {
                    $summary['errors'][] = [
                        'account_no' => $account->account_no,
                        'reason'     => $e->getMessage(),
                    ];
                    Log::error("FD sweep error on {$account->account_no}: ".$e->getMessage());
                }
            });

        return $summary;
    }

    private function postMaturityInterest(SavingsAccount $account, int $actorId): void
    {
        $product    = $account->savingsProduct;
        $from       = Carbon::parse($account->last_interest_posted_at ?? $account->created_at);
        $to         = Carbon::parse($account->maturity_date);
        $principal  = (float) $account->balance;
        $rate       = (float) ($product?->interest_rate ?? $account->interest_rate ?? 0);
        $interest   = $this->calculator->calculateInterest($principal, $rate, $from, $to);

        if ($interest > 0) {
            $this->postAndRecord($account, $product, $interest, $from->toDateString(), $to->toDateString(), $actorId);
            $account->increment('balance', $interest);
        }

        $account->update(['last_interest_posted_at' => now()]);
        $this->maturity->execute($account, $actorId);
    }

    private function postPeriodicInterest(SavingsAccount $account, int $actorId): void
    {
        $product   = $account->savingsProduct;
        $from      = Carbon::parse($account->last_interest_posted_at ?? $account->created_at);
        $to        = Carbon::parse($account->next_interest_date);
        $principal = (float) $account->balance;
        $rate      = (float) ($product?->interest_rate ?? $account->interest_rate ?? 0);
        $interest  = $this->calculator->calculateInterest($principal, $rate, $from, $to);

        if ($interest <= 0) {
            return;
        }

        $payoutType = $product?->interest_payout_type ?? 'periodic_payout';
        $this->postAndRecord($account, $product, $interest, $from->toDateString(), $to->toDateString(), $actorId);

        if ($payoutType === 'compound') {
            $account->increment('balance', $interest);
        } else {
            // periodic_payout — credit member's payout savings account balance
            $payoutAccount = $account->payoutSavingsAccount;
            if ($payoutAccount) {
                $payoutAccount->increment('balance', $interest);
            }
        }

        $frequency    = $product?->interest_posting_frequency ?? 'monthly';
        $nextDate     = $this->calculator->nextInterestDate($to, $frequency);
        $account->update([
            'last_interest_posted_at' => now(),
            'next_interest_date'      => $nextDate,
        ]);
    }

    private function postAndRecord(
        SavingsAccount $account,
        mixed $product,
        float $interest,
        string $periodStart,
        string $periodEnd,
        int $actorId,
    ): void {
        $expenseAccountId  = $product?->interest_expense_account_id;
        $payableAccountId  = $product?->interest_payable_account_id;

        $expenseAccount = $expenseAccountId
            ? ChartOfAccount::on('tenant')->findOrFail($expenseAccountId)
            : null;
        $creditAccount  = $payableAccountId
            ? ChartOfAccount::on('tenant')->findOrFail($payableAccountId)
            : null;

        $je = null;
        if ($expenseAccount && $creditAccount) {
            $je = $this->journal->postInterest(
                $account, $expenseAccount, $creditAccount,
                $interest, $periodStart, $periodEnd, $actorId,
            );
        }

        SavingsInterestPosting::on('tenant')->create([
            'savings_account_id' => $account->id,
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'principal'          => $account->balance,
            'rate'               => $product?->interest_rate ?? $account->interest_rate ?? 0,
            'interest_amount'    => $interest,
            'payout_type'        => $product?->interest_payout_type ?? 'at_maturity',
            'journal_entry_id'   => $je?->id,
            'posted_by'          => $actorId,
            'created_at'         => now(),
        ]);
    }
}
```

- [ ] **Step 2: Verify syntax and line count**

```bash
wc -l app/Tenant/Modules/Savings/Services/FixedDepositInterestService.php
php -l app/Tenant/Modules/Savings/Services/FixedDepositInterestService.php
```

Expected: under 170 lines, no syntax errors.

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Savings/Services/FixedDepositInterestService.php
git commit -m "feat(savings): add FixedDepositInterestService with processAccount and runMonthEndSweep"
```

---

### Task 8: Extend SavingsAccountService::create() for FD Fields

**Files:**
- Modify: `app/Tenant/Modules/Savings/Services/SavingsAccountService.php`

- [ ] **Step 1: Add FD date computation inside the create() transaction, after `$data['interest_rate'] = ...`**

```php
// After: $data['interest_rate'] = $product->interest_rate ?? 0;
// Add:
if ($product->isFixed()) {
    $tenor = (int) ($data['tenor_months'] ?? $product->default_tenor_months ?? 6);
    $openedAt = now();
    $data['tenor_months']   = $tenor;
    $data['maturity_date']  = $openedAt->copy()->addMonths($tenor)->toDateString();
    $data['account_type']   = 'fixed';
    $data['maturity_action'] = $data['maturity_action'] ?? $product->maturity_action ?? 'manual';

    if (in_array($product->interest_payout_type, ['periodic_payout', 'compound'], true)) {
        $frequency = $product->interest_posting_frequency ?? 'monthly';
        $calc = app(\App\Tenant\Modules\Savings\Services\FixedDepositCalculator::class);
        $data['next_interest_date'] = $calc->nextInterestDate($openedAt, $frequency)->toDateString();
    }
}
```

- [ ] **Step 2: Verify syntax and line count**

```bash
wc -l app/Tenant/Modules/Savings/Services/SavingsAccountService.php
php -l app/Tenant/Modules/Savings/Services/SavingsAccountService.php
```

Expected: under 190 lines, no syntax errors.

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Savings/Services/SavingsAccountService.php
git commit -m "feat(savings): compute maturity_date and next_interest_date when creating FD accounts"
```

---

### Task 9: FixedDepositController

**Files:**
- Create: `app/Tenant/Http/Controllers/Api/V1/FixedDepositController.php`

- [ ] **Step 1: Create the controller**

```php
<?php
// app/Tenant/Http/Controllers/Api/V1/FixedDepositController.php
namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsInterestPosting;
use App\Tenant\Modules\Savings\Services\FixedDepositInterestService;
use App\Tenant\Modules\Savings\Services\FixedDepositMaturityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FixedDepositController extends Controller
{
    public function __construct(
        protected FixedDepositInterestService $interestService,
        protected FixedDepositMaturityService $maturityService,
    ) {}

    /**
     * GET /savings/fixed-deposits
     * List all FD accounts with maturity and interest dates.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SavingsAccount::on('tenant')
            ->with(['member', 'savingsProduct'])
            ->where('account_type', 'fixed')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('savings_product_id')) {
            $query->where('savings_product_id', $request->savings_product_id);
        }

        return response()->json(['data' => $query->paginate(20)]);
    }

    /**
     * POST /savings/fixed-deposits/post-interest
     * Manager month-end sweep — posts all due interest.
     */
    public function postInterest(Request $request): JsonResponse
    {
        $actorId = auth()->id() ?? 1;
        $summary = $this->interestService->runMonthEndSweep($actorId);

        return response()->json([
            'message' => 'Interest posting complete.',
            'data'    => $summary,
        ]);
    }

    /**
     * POST /savings/accounts/{id}/maturity/process
     * Officer: withdraw or rollover a matured FD.
     */
    public function processMaturity(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'in:withdraw,rollover'],
        ]);

        $account = SavingsAccount::on('tenant')->findOrFail($id);

        if ($account->status !== 'matured') {
            return response()->json(['message' => 'Account is not in matured status.'], 422);
        }

        $actorId = auth()->id() ?? 1;

        DB::connection('tenant')->transaction(function () use ($account, $request, $actorId) {
            if ($request->action === 'rollover') {
                $account->update(['status' => 'active']);
                $this->maturityService->execute($account->fresh(), $actorId);
            } else {
                $account->update(['status' => 'closed']);
            }
        });

        return response()->json(['message' => 'Maturity processed successfully.']);
    }

    /**
     * GET /savings/accounts/{id}/interest-postings
     * Posting history for one FD account.
     */
    public function interestPostings(int $id): JsonResponse
    {
        $postings = SavingsInterestPosting::on('tenant')
            ->where('savings_account_id', $id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $postings]);
    }
}
```

- [ ] **Step 2: Verify syntax and line count**

```bash
wc -l app/Tenant/Http/Controllers/Api/V1/FixedDepositController.php
php -l app/Tenant/Http/Controllers/Api/V1/FixedDepositController.php
```

Expected: under 110 lines, no syntax errors.

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/FixedDepositController.php
git commit -m "feat(savings): add FixedDepositController with list, sweep, maturity, and posting history endpoints"
```

---

### Task 10: Routes + On-Access Hook

**Files:**
- Modify: `routes/tenant_api.php`
- Modify: `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php`

- [ ] **Step 1: Add import and 4 routes to tenant_api.php**

At the top of `routes/tenant_api.php`, add this use statement with the other savings imports:

```php
use App\Tenant\Http\Controllers\Api\V1\FixedDepositController;
```

Then after the existing savings routes block (after line `Route::apiResource('savings-products', SavingsProductController::class);`), add:

```php
// Fixed Deposits
Route::get('savings/fixed-deposits', [FixedDepositController::class, 'index']);
Route::post('savings/fixed-deposits/post-interest', [FixedDepositController::class, 'postInterest']);
Route::post('savings-accounts/{id}/maturity/process', [FixedDepositController::class, 'processMaturity']);
Route::get('savings-accounts/{id}/interest-postings', [FixedDepositController::class, 'interestPostings']);
```

- [ ] **Step 2: Add on-access hook to SavingsAccountController::show()**

Find the `show()` method in `SavingsAccountController.php`. It currently returns via `TenantSavingsAccountService`. Search for a `show` method or the resource controller's show action.

If there is no explicit `show()` method (the controller extends `TenantSavingsAccountService` which may handle it), add this before the return in the show method, or add an explicit show method:

```php
/**
 * GET /savings-accounts/{savingsAccount}
 * Auto-processes FD interest on access before returning account data.
 */
public function show(SavingsAccount $savingsAccount): \Illuminate\Http\JsonResponse
{
    if ($savingsAccount->isFixed()) {
        $interestService = app(\App\Tenant\Modules\Savings\Services\FixedDepositInterestService::class);
        $interestService->processAccount($savingsAccount, auth()->id() ?? 1);
        $savingsAccount->refresh();
    }

    return response()->json([
        'data' => new SavingsAccountResource($savingsAccount->load(['member', 'savingsProduct'])),
    ]);
}
```

Also add to the imports at the top of `SavingsAccountController.php`:

```php
use App\Tenant\Http\Resources\SavingsAccountResource;
```

(Add only if not already imported.)

- [ ] **Step 3: Verify routes list**

```bash
php artisan route:list | grep "fixed-deposit\|interest-posting\|maturity"
```

Expected: 4 routes printed.

- [ ] **Step 4: Verify syntax**

```bash
php -l routes/tenant_api.php
php -l app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php
```

- [ ] **Step 5: Commit**

```bash
git add routes/tenant_api.php
git add app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php
git commit -m "feat(savings): register FD routes and hook processAccount() into SavingsAccountController::show()"
```

---

### Task 11: Feature Tests

**Files:**
- Modify: `tests/Feature/Savings/FixedDepositInterestTest.php`

- [ ] **Step 1: Extend the test file with integration tests**

Replace the test file content entirely:

```php
<?php
// tests/Feature/Savings/FixedDepositInterestTest.php
namespace Tests\Feature\Savings;

use App\Domain\Tenancy\Entities\Tenant;
use App\Models\Staff;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsInterestPosting;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Services\FixedDepositCalculator;
use App\Tenant\Modules\Savings\Services\FixedDepositInterestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FixedDepositInterestTest extends TestCase
{
    use RefreshDatabase;

    protected Staff $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.central_domain', 'admin.mfukopro.test');
        $testDb = config('database.connections.mysql.database');
        Config::set('database.connections.master.database', $testDb);
        Config::set('database.connections.tenant.database', $testDb);
        DB::purge('master');
        DB::purge('tenant');

        $this->artisan('migrate', ['--path' => 'database/migrations/tenant', '--database' => 'mysql']);

        Tenant::create(['id' => 'test', 'name' => 'Test Sacco', 'subdomain' => 'test',
            'database_name' => $testDb, 'status' => 'active']);

        $this->admin = Staff::create([
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => Hash::make('password'), 'role' => 'Admin', 'is_tenant_admin' => true,
        ]);
    }

    // ── Unit: calculator ──────────────────────────────────────────────────────

    public function test_calculator_computes_simple_interest_correctly(): void
    {
        $calc = new FixedDepositCalculator;
        $interest = $calc->calculateInterest(100_000, 0.12, Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));
        $this->assertEqualsWithDelta(986.30, $interest, 0.02);
    }

    public function test_next_interest_date_monthly(): void
    {
        $calc = new FixedDepositCalculator;
        $this->assertEquals('2026-02-15', $calc->nextInterestDate(Carbon::parse('2026-01-15'), 'monthly')->toDateString());
    }

    public function test_next_interest_date_quarterly(): void
    {
        $calc = new FixedDepositCalculator;
        $this->assertEquals('2026-04-15', $calc->nextInterestDate(Carbon::parse('2026-01-15'), 'quarterly')->toDateString());
    }

    // ── Integration: processAccount ───────────────────────────────────────────

    public function test_process_account_posts_maturity_interest_and_marks_matured(): void
    {
        $product = SavingsProduct::create([
            'name' => 'FD 12M', 'code' => 'FD12', 'type' => 'fixed',
            'minimum_balance' => 0, 'minimum_maturity_months' => 0,
            'dormancy_period_months' => 0, 'status' => 'active',
            'interest_rate' => 0.12, 'interest_payout_type' => 'at_maturity',
            'default_tenor_months' => 12, 'maturity_action' => 'manual',
        ]);

        $account = SavingsAccount::create([
            'member_id' => null, 'savings_product_id' => $product->id,
            'account_no' => 'FD-000001', 'account_type' => 'fixed',
            'balance' => 100_000, 'interest_rate' => 0.12, 'status' => 'active',
            'tenor_months' => 6, 'maturity_date' => now()->subDay()->toDateString(),
        ]);

        $service = app(FixedDepositInterestService::class);
        $service->processAccount($account, 1);

        $account->refresh();
        $this->assertEquals('matured', $account->status);
        $this->assertGreaterThan(100_000, (float) $account->balance);
        $this->assertDatabaseHas('savings_interest_postings', ['savings_account_id' => $account->id]);
    }

    public function test_process_account_is_idempotent_on_double_call(): void
    {
        $product = SavingsProduct::create([
            'name' => 'FD 6M', 'code' => 'FD6', 'type' => 'fixed',
            'minimum_balance' => 0, 'minimum_maturity_months' => 0,
            'dormancy_period_months' => 0, 'status' => 'active',
            'interest_rate' => 0.12, 'interest_payout_type' => 'at_maturity',
            'default_tenor_months' => 6, 'maturity_action' => 'manual',
        ]);

        $account = SavingsAccount::create([
            'member_id' => null, 'savings_product_id' => $product->id,
            'account_no' => 'FD-000002', 'account_type' => 'fixed',
            'balance' => 50_000, 'interest_rate' => 0.12, 'status' => 'active',
            'tenor_months' => 6, 'maturity_date' => now()->subDay()->toDateString(),
        ]);

        $service = app(FixedDepositInterestService::class);
        $service->processAccount($account, 1);
        $service->processAccount($account->fresh(), 1); // second call — should skip

        $this->assertCount(1, SavingsInterestPosting::where('savings_account_id', $account->id)->get());
    }

    public function test_month_end_sweep_processes_all_due_accounts(): void
    {
        $product = SavingsProduct::create([
            'name' => 'FD Sweep', 'code' => 'FDS', 'type' => 'fixed',
            'minimum_balance' => 0, 'minimum_maturity_months' => 0,
            'dormancy_period_months' => 0, 'status' => 'active',
            'interest_rate' => 0.12, 'interest_payout_type' => 'periodic_payout',
            'interest_posting_frequency' => 'monthly',
            'default_tenor_months' => 12, 'maturity_action' => 'manual',
        ]);

        foreach (['FD-A', 'FD-B'] as $no) {
            SavingsAccount::create([
                'member_id' => null, 'savings_product_id' => $product->id,
                'account_no' => $no, 'account_type' => 'fixed',
                'balance' => 100_000, 'interest_rate' => 0.12, 'status' => 'active',
                'tenor_months' => 12, 'maturity_date' => now()->addYear()->toDateString(),
                'next_interest_date' => now()->subDay()->toDateString(),
            ]);
        }

        $service = app(FixedDepositInterestService::class);
        $result = $service->runMonthEndSweep(1);

        $this->assertEquals(2, $result['posted']);
        $this->assertEquals(0, $result['errors']);
    }
}
```

- [ ] **Step 2: Run the full test suite**

```bash
php artisan test --filter=FixedDepositInterestTest
```

Expected: All tests pass.

- [ ] **Step 3: Run full test suite to check no regressions**

```bash
composer test
```

Expected: All tests pass (lint + PHP tests).

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Savings/FixedDepositInterestTest.php
git commit -m "test(savings): add FixedDepositInterestTest covering calculator, processAccount, idempotency, and sweep"
```

---

## Self-Review Checklist

- [ ] All 3 migrations created and run cleanly
- [ ] `SavingsInterestPosting` model exists with correct fillable/casts
- [ ] `SavingsProduct` and `SavingsAccount` models have FD fields in fillable + casts
- [ ] `SavingsProductFormRequest` validates FD fields when `type=fixed`
- [ ] `FixedDepositCalculator` is pure — no DB calls, no dependencies
- [ ] `SavingsJournalService::postInterest()` follows same pattern as `postDeposit()`
- [ ] `FixedDepositMaturityService` handles all 3 maturity actions, falls back gracefully
- [ ] `FixedDepositInterestService::processAccount()` uses `lockForUpdate()` — race-safe
- [ ] `SavingsAccountService::create()` sets `maturity_date` and `next_interest_date` for FD
- [ ] `FixedDepositController` has 4 methods, all under 200 lines individually
- [ ] 4 new routes registered and visible in `php artisan route:list`
- [ ] `SavingsAccountController::show()` calls `processAccount()` for FD accounts
- [ ] All tests pass; no file exceeds 400 lines (PHP classes) or 200 lines (methods)
