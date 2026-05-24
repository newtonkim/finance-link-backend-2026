# General Charges → Accounting Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Link `general_charges` to `savings_product_charges` and `loan_product_charges` so every savings/loan charge collected creates a correct double-entry journal entry using the GL accounts defined on the `general_charge` record instead of the hardcoded GL code `4230`.

**Architecture:** Add a `general_charge_id` FK to `savings_product_charges`, then build three focused services — `ChargeCalculatorService` (resolves which charge and how much), `ChargeJournalService` (posts the double-entry JE), and `ChargeApplicationService` (orchestrates both plus the `MemberCharge` record). Finally wire these into the existing `MemberChargeService::collectCharge()` call so that every collected savings charge flows through the correct income GL from `general_charges.credit_account_id`.

**Tech Stack:** Laravel 12, PHP 8.3+, Pest, bcmath (already used in `GlPostingEngine`), `Schema::connection('tenant')` for tenant migrations

---

## File Manifest

### Create
- `database/migrations/tenant/2026_05_07_000001_add_general_charge_id_to_savings_product_charges.php`
- `app/Tenant/Modules/Charges/Contracts/ChargeCalculatorServiceInterface.php`
- `app/Tenant/Modules/Charges/Contracts/ChargeJournalServiceInterface.php`
- `app/Tenant/Modules/Charges/Contracts/ChargeApplicationServiceInterface.php`
- `app/Tenant/Modules/Charges/Services/ChargeCalculatorService.php`
- `app/Tenant/Modules/Charges/Services/ChargeJournalService.php`
- `app/Tenant/Modules/Charges/Services/ChargeApplicationService.php`
- `tests/Unit/Charges/ChargeCalculatorServiceTest.php`
- `tests/Unit/Charges/ChargeJournalServiceTest.php`
- `tests/Unit/Charges/ChargeApplicationServiceTest.php`

### Modify
- `app/Tenant/Modules/Savings/Models/SavingsProductCharge.php` — add `general_charge_id` to `$fillable`, add `generalCharge()` relation
- `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` — update `postCharge()` to accept optional `?ChartOfAccount $creditAccount` parameter
- `app/Tenant/Modules/Members/Services/MemberChargeService.php` — resolve credit account from `general_charge` and pass to `postCharge()`
- `app/Providers/AppServiceProvider.php` — bind all three new interfaces

---

## Task 1: Migration — add `general_charge_id` to `savings_product_charges`

**Files:**
- Create: `database/migrations/tenant/2026_05_07_000001_add_general_charge_id_to_savings_product_charges.php`

- [ ] **Step 1: Create the migration file**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('savings_product_charges', function (Blueprint $table) {
            $table->unsignedBigInteger('general_charge_id')->nullable()->after('savings_product_id');
            $table->foreign('general_charge_id')
                ->references('id')
                ->on('general_charges')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('savings_product_charges', function (Blueprint $table) {
            $table->dropForeign(['general_charge_id']);
            $table->dropColumn('general_charge_id');
        });
    }
};
```

- [ ] **Step 2: Run the migration on all tenant databases**

```bash
php artisan tenants:migrate
```

Expected: `Migrating: 2026_05_07_000001_add_general_charge_id...` per tenant, no errors.

- [ ] **Step 3: Verify column was added**

```bash
php artisan tinker --execute="DB::connection('tenant')->select('DESCRIBE savings_product_charges');" | grep general_charge_id
```

Expected: one row showing `general_charge_id` as a nullable bigint column.

- [ ] **Step 4: Update `SavingsProductCharge` model**

Open `app/Tenant/Modules/Savings/Models/SavingsProductCharge.php`.

Replace the `$fillable` array and add the relation:

```php
protected $fillable = [
    'savings_product_id',
    'general_charge_id',
    'name',
    'type',
    'minimum_amount',
    'maximum_amount',
    'charge_type',
    'amount',
    'is_reversible',
];

public function generalCharge(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(\App\Tenant\Modules\Savings\Models\GeneralCharge::class);
}
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/tenant/2026_05_07_000001_add_general_charge_id_to_savings_product_charges.php
git add app/Tenant/Modules/Savings/Models/SavingsProductCharge.php
git commit -m "feat: add general_charge_id FK to savings_product_charges"
```

---

## Task 2: `ChargeCalculatorServiceInterface` + `ChargeCalculatorService`

**Files:**
- Create: `app/Tenant/Modules/Charges/Contracts/ChargeCalculatorServiceInterface.php`
- Create: `app/Tenant/Modules/Charges/Services/ChargeCalculatorService.php`
- Create: `tests/Unit/Charges/ChargeCalculatorServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Charges/ChargeCalculatorServiceTest.php

use App\Tenant\Modules\Charges\Services\ChargeCalculatorService;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns null when no savings_product_charge has a general_charge_id for the given event type', function () {
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
    SavingsProductCharge::factory()->create([
        'savings_product_id' => $product->id,
        'type' => 'deposit',
        'general_charge_id' => null,
    ]);

    $service = new ChargeCalculatorService();
    $result = $service->resolveForSavings($account->id, 'deposit', 500.00);

    expect($result)->toBeNull();
});

it('returns correct fixed fee when a general_charge_id is linked', function () {
    $generalCharge = GeneralCharge::factory()->create([
        'charge_type' => 'amount',
        'amount' => 50.00,
        'interval_type' => 'deposit',
    ]);
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
    SavingsProductCharge::factory()->create([
        'savings_product_id' => $product->id,
        'type' => 'deposit',
        'general_charge_id' => $generalCharge->id,
    ]);

    $service = new ChargeCalculatorService();
    $result = $service->resolveForSavings($account->id, 'deposit', 1000.00);

    expect($result)->not->toBeNull()
        ->and($result['fee'])->toBe(50.00)
        ->and($result['charge']->id)->toBe($generalCharge->id);
});

it('computes a percentage fee correctly', function () {
    $generalCharge = GeneralCharge::factory()->create([
        'charge_type' => 'percentage',
        'amount' => 2.50, // 2.5%
        'interval_type' => 'deposit',
    ]);
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id]);
    SavingsProductCharge::factory()->create([
        'savings_product_id' => $product->id,
        'type' => 'deposit',
        'general_charge_id' => $generalCharge->id,
    ]);

    $service = new ChargeCalculatorService();
    $result = $service->resolveForSavings($account->id, 'deposit', 1000.00);

    expect($result['fee'])->toBe(25.00); // 1000 * 2.5 / 100
});
```

- [ ] **Step 2: Run to confirm it fails**

```bash
php artisan test --filter=ChargeCalculatorServiceTest
```

Expected: FAIL — class `ChargeCalculatorService` not found.

- [ ] **Step 3: Create the interface**

```php
<?php
// app/Tenant/Modules/Charges/Contracts/ChargeCalculatorServiceInterface.php

namespace App\Tenant\Modules\Charges\Contracts;

use App\Tenant\Modules\Savings\Models\GeneralCharge;

interface ChargeCalculatorServiceInterface
{
    /**
     * Returns ['charge' => GeneralCharge, 'fee' => float] or null if no charge applies.
     */
    public function resolveForSavings(int $savingsAccountId, string $eventType, float $transactionAmount): ?array;
}
```

- [ ] **Step 4: Implement the service**

```php
<?php
// app/Tenant/Modules/Charges/Services/ChargeCalculatorService.php

namespace App\Tenant\Modules\Charges\Services;

use App\Tenant\Modules\Charges\Contracts\ChargeCalculatorServiceInterface;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;

class ChargeCalculatorService implements ChargeCalculatorServiceInterface
{
    public function resolveForSavings(int $savingsAccountId, string $eventType, float $transactionAmount): ?array
    {
        $account = SavingsAccount::on('tenant')->find($savingsAccountId);
        if (! $account) {
            return null;
        }

        $productCharge = SavingsProductCharge::on('tenant')
            ->where('savings_product_id', $account->savings_product_id)
            ->where('type', $eventType)
            ->whereNotNull('general_charge_id')
            ->first();

        if (! $productCharge || ! $productCharge->general_charge_id) {
            return null;
        }

        $charge = GeneralCharge::on('tenant')->find($productCharge->general_charge_id);
        if (! $charge) {
            return null;
        }

        $fee = $charge->charge_type === 'percentage'
            ? round($transactionAmount * ((float) $charge->amount / 100), 2)
            : (float) $charge->amount;

        if ($fee <= 0) {
            return null;
        }

        return ['charge' => $charge, 'fee' => $fee];
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --filter=ChargeCalculatorServiceTest
```

Expected: 3 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Tenant/Modules/Charges/Contracts/ChargeCalculatorServiceInterface.php
git add app/Tenant/Modules/Charges/Services/ChargeCalculatorService.php
git add tests/Unit/Charges/ChargeCalculatorServiceTest.php
git commit -m "feat: add ChargeCalculatorService — resolves savings charge from savings_product_charges"
```

---

## Task 3: `ChargeJournalServiceInterface` + `ChargeJournalService`

**Files:**
- Create: `app/Tenant/Modules/Charges/Contracts/ChargeJournalServiceInterface.php`
- Create: `app/Tenant/Modules/Charges/Services/ChargeJournalService.php`
- Create: `tests/Unit/Charges/ChargeJournalServiceTest.php`
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` — add optional `?ChartOfAccount $creditAccount` to `postCharge()`

**Context:** `SavingsJournalService::postCharge()` currently hardcodes `$this->coa->resolveByGlCode('4230')` as the fee income account. We need to allow an explicitly resolved credit account to be passed in, so the caller (eventually `MemberChargeService`) can supply the one from `general_charge.credit_account_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Charges/ChargeJournalServiceTest.php

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Services\GlPostingEngine;
use App\Tenant\Modules\Accounting\Services\JournalSequenceService;
use App\Tenant\Modules\Accounting\Services\SavingsCoaResolver;
use App\Tenant\Modules\Charges\Services\ChargeJournalService;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('posts DR savings liability CR income when credit_account_id is set on the charge', function () {
    $savingsGl = ChartOfAccount::factory()->create(['gl_code' => '2112', 'normal_balance' => 'CR', 'is_active' => true]);
    $incomeGl  = ChartOfAccount::factory()->create(['gl_code' => '4100', 'normal_balance' => 'CR', 'is_active' => true]);

    $generalCharge = GeneralCharge::factory()->create(['credit_account_id' => $incomeGl->id]);
    $account = SavingsAccount::factory()->create(['member_id' => 1]);

    $service = app(ChargeJournalService::class);
    $je = $service->post(
        charge: $generalCharge,
        fee: 50.00,
        memberId: 1,
        savingsAccount: $account,
        reference: 'TEST-REF-001',
        postedBy: 1
    );

    expect($je)->toBeInstanceOf(JournalEntry::class);

    $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();
    expect($lines)->toHaveCount(2);

    $drLine = $lines->firstWhere('debit', '>', 0);
    $crLine = $lines->firstWhere('credit', '>', 0);

    expect((float) $drLine->debit)->toBe(50.0);
    expect((float) $crLine->credit)->toBe(50.0);
    expect($crLine->account_id)->toBe($incomeGl->id);
});

it('throws DomainException when credit_account_id on the charge does not exist in COA', function () {
    $generalCharge = GeneralCharge::factory()->create(['credit_account_id' => 99999]);
    $account = SavingsAccount::factory()->create(['member_id' => 1]);

    $service = app(ChargeJournalService::class);

    expect(fn () => $service->post($generalCharge, 50.00, 1, $account, 'REF-002', 1))
        ->toThrow(\DomainException::class);
});
```

- [ ] **Step 2: Run to confirm it fails**

```bash
php artisan test --filter=ChargeJournalServiceTest
```

Expected: FAIL — class `ChargeJournalService` not found.

- [ ] **Step 3: Create the interface**

```php
<?php
// app/Tenant/Modules/Charges/Contracts/ChargeJournalServiceInterface.php

namespace App\Tenant\Modules\Charges\Contracts;

use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

interface ChargeJournalServiceInterface
{
    public function post(
        GeneralCharge $charge,
        float $fee,
        int $memberId,
        SavingsAccount $savingsAccount,
        string $reference,
        int $postedBy,
    ): JournalEntry;
}
```

- [ ] **Step 4: Implement `ChargeJournalService`**

```php
<?php
// app/Tenant/Modules/Charges/Services/ChargeJournalService.php

namespace App\Tenant\Modules\Charges\Services;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Services\GlPostingEngine;
use App\Tenant\Modules\Accounting\Services\JournalSequenceService;
use App\Tenant\Modules\Accounting\Services\SavingsCoaResolverInterface;
use App\Tenant\Modules\Charges\Contracts\ChargeJournalServiceInterface;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

class ChargeJournalService implements ChargeJournalServiceInterface
{
    public function __construct(
        private readonly JournalSequenceService $sequence,
        private readonly GlPostingEngine $gl,
        private readonly \App\Tenant\Modules\Accounting\Contracts\SavingsCoaResolverInterface $coa,
    ) {}

    public function post(
        GeneralCharge $charge,
        float $fee,
        int $memberId,
        SavingsAccount $savingsAccount,
        string $reference,
        int $postedBy,
    ): JournalEntry {
        $date = now()->toDateString();
        $narration = "Charge: {$charge->name} – {$savingsAccount->account_no}";

        // Resolve DR: member savings liability account
        $savingsAccount->loadMissing('savingsProduct');
        $debitAccount = $this->coa->resolveSavingsLiabilityAccount($savingsAccount);

        // Resolve CR: income account from the general_charge record
        if (! $charge->credit_account_id) {
            throw new \DomainException("GeneralCharge #{$charge->id} has no credit_account_id configured.");
        }

        $creditAccount = ChartOfAccount::on('tenant')
            ->where('id', $charge->credit_account_id)
            ->where('is_active', true)
            ->first();

        if (! $creditAccount) {
            throw new \DomainException(
                "GL account {$charge->credit_account_id} not found or inactive for GeneralCharge #{$charge->id}."
            );
        }

        $je = JournalEntry::create([
            'entry_no'     => $this->sequence->nextEntryNo('CHG'),
            'date'         => $date,
            'period_date'  => $date,
            'fiscal_period' => substr($date, 0, 7),
            'reference'    => $reference,
            'narration'    => $narration,
            'journal_type' => 'SAVINGS_CHARGE',
            'status'       => 'posted',
            'is_system'    => true,
            'posted_at'    => now(),
            'posted_by'    => $postedBy,
        ]);

        // DR member savings liability
        $this->writeLine($je, $debitAccount, $fee, 0.0, $narration, 1, $date, $memberId, $savingsAccount->id);

        // CR fee income
        $this->writeLine($je, $creditAccount, 0.0, $fee, $narration, 2, $date);

        return $je;
    }

    private function writeLine(
        JournalEntry $je,
        ChartOfAccount $account,
        float $debit,
        float $credit,
        string $narration,
        int $lineNo,
        string $date,
        ?int $memberId = null,
        ?int $savingsId = null,
    ): JournalEntryLine {
        $attrs = [
            'journal_entry_id' => $je->id,
            'account_id'       => $account->id,
            'debit'            => $debit,
            'credit'           => $credit,
            'narration'        => $narration,
            'line_no'          => $lineNo,
        ];
        if ($memberId !== null) {
            $attrs['member_id'] = $memberId;
        }
        if ($savingsId !== null) {
            $attrs['savings_id'] = $savingsId;
        }

        $line = JournalEntryLine::create($attrs);

        $this->gl->postToGeneralLedger(
            $je->id, $account->id,
            $debit, $credit,
            $date, $narration, $account->normal_balance ?? 'CR',
        );

        if ($memberId !== null) {
            $this->gl->postToSubLedger(
                $je->id, $account->id,
                $memberId, Member::class,
                $debit, $credit,
                $date, $narration, $account->normal_balance ?? 'CR',
            );
        }

        return $line;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --filter=ChargeJournalServiceTest
```

Expected: 2 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Tenant/Modules/Charges/Contracts/ChargeJournalServiceInterface.php
git add app/Tenant/Modules/Charges/Services/ChargeJournalService.php
git add tests/Unit/Charges/ChargeJournalServiceTest.php
git commit -m "feat: add ChargeJournalService — posts double-entry JE using general_charge.credit_account_id"
```

---

## Task 4: `ChargeApplicationServiceInterface` + `ChargeApplicationService`

**Files:**
- Create: `app/Tenant/Modules/Charges/Contracts/ChargeApplicationServiceInterface.php`
- Create: `app/Tenant/Modules/Charges/Services/ChargeApplicationService.php`
- Create: `tests/Unit/Charges/ChargeApplicationServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Charges/ChargeApplicationServiceTest.php

use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Charges\Services\ChargeApplicationService;
use App\Tenant\Modules\Members\Models\MemberCharge;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns null when no charge applies for the event type', function () {
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id, 'member_id' => 1]);

    $service = app(ChargeApplicationService::class);
    $result = $service->applyForSavingsEvent($account->id, 'deposit', 500.00, transactionId: null, actorId: 1);

    expect($result)->toBeNull();
    expect(MemberCharge::count())->toBe(0);
});

it('creates a MemberCharge and posts a JournalEntry on first application', function () {
    $incomeGl = ChartOfAccount::factory()->create(['gl_code' => '4100', 'normal_balance' => 'CR', 'is_active' => true]);
    ChartOfAccount::factory()->create(['gl_code' => '2112', 'normal_balance' => 'CR', 'is_active' => true]);

    $generalCharge = GeneralCharge::factory()->create([
        'charge_type' => 'amount',
        'amount' => 30.00,
        'interval_type' => 'deposit',
        'credit_account_id' => $incomeGl->id,
        'name' => 'Deposit Fee',
    ]);
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id, 'member_id' => 1]);
    SavingsProductCharge::factory()->create([
        'savings_product_id' => $product->id,
        'type' => 'deposit',
        'general_charge_id' => $generalCharge->id,
    ]);

    $service = app(ChargeApplicationService::class);
    $memberCharge = $service->applyForSavingsEvent($account->id, 'deposit', 1000.00, transactionId: null, actorId: 1);

    expect($memberCharge)->toBeInstanceOf(MemberCharge::class)
        ->and((float) $memberCharge->amount)->toBe(30.0)
        ->and($memberCharge->status)->toBe('applied')
        ->and($memberCharge->general_charge_id)->toBe($generalCharge->id);

    expect(JournalEntry::where('journal_type', 'SAVINGS_CHARGE')->count())->toBe(1);
});

it('is idempotent — calling twice with the same transaction_id creates only one MemberCharge', function () {
    $incomeGl = ChartOfAccount::factory()->create(['gl_code' => '4100', 'normal_balance' => 'CR', 'is_active' => true]);
    ChartOfAccount::factory()->create(['gl_code' => '2112', 'normal_balance' => 'CR', 'is_active' => true]);

    $generalCharge = GeneralCharge::factory()->create([
        'charge_type' => 'amount',
        'amount' => 20.00,
        'interval_type' => 'deposit',
        'credit_account_id' => $incomeGl->id,
    ]);
    $product = SavingsProduct::factory()->create();
    $account = SavingsAccount::factory()->create(['savings_product_id' => $product->id, 'member_id' => 1]);
    SavingsProductCharge::factory()->create([
        'savings_product_id' => $product->id,
        'type' => 'deposit',
        'general_charge_id' => $generalCharge->id,
    ]);

    $service = app(ChargeApplicationService::class);
    $first  = $service->applyForSavingsEvent($account->id, 'deposit', 500.00, transactionId: 42, actorId: 1);
    $second = $service->applyForSavingsEvent($account->id, 'deposit', 500.00, transactionId: 42, actorId: 1);

    expect(MemberCharge::count())->toBe(1)
        ->and($first->id)->toBe($second->id);
});
```

- [ ] **Step 2: Run to confirm it fails**

```bash
php artisan test --filter=ChargeApplicationServiceTest
```

Expected: FAIL — class `ChargeApplicationService` not found.

- [ ] **Step 3: Create the interface**

```php
<?php
// app/Tenant/Modules/Charges/Contracts/ChargeApplicationServiceInterface.php

namespace App\Tenant\Modules\Charges\Contracts;

use App\Tenant\Modules\Members\Models\MemberCharge;

interface ChargeApplicationServiceInterface
{
    /**
     * Calculate the applicable charge, record a MemberCharge, post the JE.
     * Returns null if no charge applies. Idempotent on transactionId.
     */
    public function applyForSavingsEvent(
        int $savingsAccountId,
        string $eventType,
        float $transactionAmount,
        ?int $transactionId,
        int $actorId,
    ): ?MemberCharge;
}
```

- [ ] **Step 4: Implement the service**

```php
<?php
// app/Tenant/Modules/Charges/Services/ChargeApplicationService.php

namespace App\Tenant\Modules\Charges\Services;

use App\Tenant\Modules\Charges\Contracts\ChargeApplicationServiceInterface;
use App\Tenant\Modules\Charges\Contracts\ChargeCalculatorServiceInterface;
use App\Tenant\Modules\Charges\Contracts\ChargeJournalServiceInterface;
use App\Tenant\Modules\Members\Models\MemberCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Support\Facades\DB;

class ChargeApplicationService implements ChargeApplicationServiceInterface
{
    public function __construct(
        private readonly ChargeCalculatorServiceInterface $calculator,
        private readonly ChargeJournalServiceInterface $journal,
    ) {}

    public function applyForSavingsEvent(
        int $savingsAccountId,
        string $eventType,
        float $transactionAmount,
        ?int $transactionId,
        int $actorId,
    ): ?MemberCharge {
        $resolved = $this->calculator->resolveForSavings($savingsAccountId, $eventType, $transactionAmount);
        if (! $resolved) {
            return null;
        }

        ['charge' => $charge, 'fee' => $fee] = $resolved;

        // Idempotency: if this transaction already has a charge for this general_charge, return it
        if ($transactionId !== null) {
            $existing = MemberCharge::on('tenant')
                ->where('general_charge_id', $charge->id)
                ->where('transaction_id', $transactionId)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $account = SavingsAccount::on('tenant')->findOrFail($savingsAccountId);

        return DB::connection('tenant')->transaction(function () use ($charge, $fee, $account, $transactionId, $actorId) {
            $reference = 'CHG-'.now()->format('YmdHis').'-'.mt_rand(1000, 9999);

            $memberCharge = MemberCharge::create([
                'member_id'         => $account->member_id,
                'general_charge_id' => $charge->id,
                'savings_account_id' => $account->id,
                'charge_name'       => $charge->name,
                'amount'            => $fee,
                'status'            => 'applied',
                'applied_at'        => now(),
                'transaction_id'    => $transactionId,
                'narration'         => "Auto-applied: {$charge->name}",
                'created_by'        => $actorId,
            ]);

            $this->journal->post(
                charge: $charge,
                fee: $fee,
                memberId: $account->member_id,
                savingsAccount: $account,
                reference: $reference,
                postedBy: $actorId,
            );

            return $memberCharge;
        });
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --filter=ChargeApplicationServiceTest
```

Expected: 3 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Tenant/Modules/Charges/Contracts/ChargeApplicationServiceInterface.php
git add app/Tenant/Modules/Charges/Services/ChargeApplicationService.php
git add tests/Unit/Charges/ChargeApplicationServiceTest.php
git commit -m "feat: add ChargeApplicationService — orchestrates charge calculation, MemberCharge record, and JE"
```

---

## Task 5: Register all three services in `AppServiceProvider`

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Add imports and bindings**

Open `app/Providers/AppServiceProvider.php`. In the `use` imports block, add:

```php
use App\Tenant\Modules\Charges\Contracts\ChargeApplicationServiceInterface;
use App\Tenant\Modules\Charges\Contracts\ChargeCalculatorServiceInterface;
use App\Tenant\Modules\Charges\Contracts\ChargeJournalServiceInterface;
use App\Tenant\Modules\Charges\Services\ChargeApplicationService;
use App\Tenant\Modules\Charges\Services\ChargeCalculatorService;
use App\Tenant\Modules\Charges\Services\ChargeJournalService;
```

In the `register()` method, after the existing bindings, add:

```php
$this->app->bind(
    ChargeCalculatorServiceInterface::class,
    ChargeCalculatorService::class
);

$this->app->bind(
    ChargeJournalServiceInterface::class,
    ChargeJournalService::class
);

$this->app->bind(
    ChargeApplicationServiceInterface::class,
    ChargeApplicationService::class
);
```

- [ ] **Step 2: Verify the container resolves correctly**

```bash
php artisan tinker --execute="app(\App\Tenant\Modules\Charges\Contracts\ChargeApplicationServiceInterface::class);"
```

Expected: object of type `ChargeApplicationService`.

- [ ] **Step 3: Run full test suite to make sure nothing broke**

```bash
php artisan test
```

Expected: all previously passing tests still PASS, new tests PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Providers/AppServiceProvider.php
git commit -m "feat: bind Charge service interfaces in AppServiceProvider"
```

---

## Task 6: Fix `SavingsJournalService::postCharge()` hardcoded GL 4230

**Context:** `postCharge()` at line 79 of `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` does `$this->coa->resolveByGlCode('4230')`. `MemberChargeService::collectCharge()` at line 85 calls `$savingsJournal->postCharge($transaction, $account)`. The fix is: if the transaction carries a `gl_credit_account_id` (the income GL from `general_charge.credit_account_id`), use that account; otherwise fall back to resolving by GL code `4230`.

**Files:**
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` — `postCharge()` method only

- [ ] **Step 1: Update `postCharge()` to use the stored credit account when available**

In `SavingsJournalService.php`, replace the `postCharge()` method (lines 74–92) with:

```php
/**
 * DR Member Savings Liability  CR Fee Income
 * Uses the income GL stored on the transaction when available,
 * otherwise falls back to the account maintenance fee GL (4230).
 */
public function postCharge(Transaction $transaction, SavingsAccount $account): ?JournalEntry
{
    return $this->safe(function () use ($transaction, $account) {
        $account->loadMissing('savingsProduct');
        $savings = $this->coa->resolveSavingsLiabilityAccount($account);

        $fee = $transaction->gl_credit_account_id
            ? (ChartOfAccount::on('tenant')
                ->where('id', $transaction->gl_credit_account_id)
                ->where('is_active', true)
                ->first() ?? $this->coa->resolveByGlCode('4230'))
            : $this->coa->resolveByGlCode('4230');

        return $this->post(
            journalType: 'SAVINGS_CHARGE',
            transaction: $transaction,
            account: $account,
            narration: $transaction->narration ?? "Savings charge – {$account->account_no}",
            lines: [
                $this->line($savings, debit: $transaction->amount, memberId: $transaction->member_id, savingsId: $account->id),
                $this->line($fee, credit: $transaction->amount),
            ]
        );
    });
}
```

- [ ] **Step 2: Run the test suite**

```bash
php artisan test
```

Expected: all tests PASS. No regressions.

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Accounting/Services/SavingsJournalService.php
git commit -m "fix: use general_charge.credit_account_id in postCharge() instead of hardcoded GL 4230"
```

---

## Task 7: Wire `ChargeApplicationService` into `MemberChargeService`

**Context:** `MemberChargeService::collectCharge()` (line 66–85 of `app/Tenant/Modules/Members/Services/MemberChargeService.php`) already resolves `$creditAccountId` from `$memberCharge->generalCharge?->credit_account_id` and stores it on the transaction. That covers the `postCharge()` fix in Task 6. For NEW charges applied during a savings deposit event, `ChargeApplicationService::applyForSavingsEvent()` should be called right after the deposit transaction is created.

The right integration point is **not** `MemberChargeService` (that service collects pre-created pending charges). The right point is `SavingsAccountController::deposit()` — but per SOLID principles, we wire it through the `SavingsAccountService` if one exists, or directly in the controller method as an inline call.

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php`

Look at lines ~400–430 where the controller calls `$this->savingsJournal->postCharge(...)` after a deposit. We will add a call to `ChargeApplicationService` after the deposit transaction is confirmed. However, because this controller is large and already calls `postCharge()` directly on the same flow, we do a minimal, targeted change.

- [ ] **Step 1: Inject `ChargeApplicationServiceInterface` into the controller**

Open `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php`.

Add to the `use` imports at the top:

```php
use App\Tenant\Modules\Charges\Contracts\ChargeApplicationServiceInterface;
```

Find the constructor. It currently injects `SavingsJournalService` and other dependencies. Add `ChargeApplicationServiceInterface` as a constructor parameter:

```php
public function __construct(
    // ... existing parameters ...
    private readonly ChargeApplicationServiceInterface $chargeApplication,
) {}
```

- [ ] **Step 2: Add the charge application call after deposit transaction creation**

In the controller's deposit method, after the line `$this->savingsJournal->postDeposit($transaction, $savingsAccount)`, add:

```php
$this->chargeApplication->applyForSavingsEvent(
    savingsAccountId: $savingsAccount->id,
    eventType: 'deposit',
    transactionAmount: (float) $transaction->amount,
    transactionId: $transaction->id,
    actorId: $transaction->created_by ?? auth()->id(),
);
```

Do the same after the withdrawal `postWithdrawal` call, using `eventType: 'withdraw'`.

- [ ] **Step 3: Run the full test suite**

```bash
php artisan test
```

Expected: all tests PASS.

- [ ] **Step 4: Manually verify on a test tenant**

```bash
# Create a deposit on a savings account that has a product with a linked general_charge
# Then check:
php artisan tinker --execute="
\$last = \App\Tenant\Modules\Members\Models\MemberCharge::on('tenant')->latest()->first();
var_dump(\$last?->toArray());
"
```

Expected: a `MemberCharge` row with `status = 'applied'` and the correct `general_charge_id`.

Also check `journal_entries`:

```bash
php artisan tinker --execute="
\$je = \App\Tenant\Modules\Accounting\Models\JournalEntry::on('tenant')->where('journal_type','SAVINGS_CHARGE')->latest()->first();
var_dump(\$je?->toArray());
\$je?->lines->each(fn(\$l) => var_dump(\$l->toArray()));
"
```

Expected: a JE with 2 lines — DR savings liability, CR the income account from `general_charge.credit_account_id`.

- [ ] **Step 5: Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php
git commit -m "feat: apply ChargeApplicationService on deposit/withdrawal events in SavingsAccountController"
```

---

## Self-Review Checklist

- [x] **Spec coverage:** Migration (Task 1) ✓ | ChargeCalculatorService (Task 2) ✓ | ChargeJournalService (Task 3) ✓ | ChargeApplicationService (Task 4) ✓ | Provider bindings (Task 5) ✓ | Fix hardcoded 4230 (Task 6) ✓ | Wire into deposit flow (Task 7) ✓
- [x] **No placeholders:** All steps contain actual code. No "TBD" or "implement later".
- [x] **Type consistency:** `ChargeApplicationService` receives `ChargeCalculatorServiceInterface` and `ChargeJournalServiceInterface` — both defined in Tasks 2–3. Method signatures in Tasks 3–4 match the interface definitions.
- [x] **Idempotency test** covers Task 4 interface's `?int $transactionId` parameter.
- [x] **Migration uses `Schema::connection('tenant')`** matching all other tenant migrations in this codebase.
- [x] **`JournalEntry::create()`** fields match the pattern in `SavingsJournalService::makeJe()`.
