# Phase 1: FD Maturity JEs + Trial Balance — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix Fixed Deposit maturity actions so they post correct double-entry journal entries, and build a Trial Balance report (backend + frontend) so the books can be verified.

**Architecture:** FD accounting follows the existing `SavingsJournalService` pattern — a dedicated `FdMaturityAccountingService` is injected into `FixedDepositMaturityService` and called inside a DB transaction. The Trial Balance is a new read-only reporting layer that aggregates `general_ledger` rows by `account_id`, joined to `chart_of_accounts`.

**Tech Stack:** Laravel 12, Pest, GlPostingEngine, JournalSequenceService, SavingsCoaResolverInterface, Vue 3, TypeScript, Tailwind CSS, tenantClient

---

## File Map

### Backend — New files
| File | Purpose |
|------|---------|
| `app/Tenant/Modules/Savings/Contracts/FdMaturityAccountingServiceInterface.php` | Interface: postRollover, postPayout, postConversion |
| `app/Tenant/Modules/Savings/Services/FdMaturityAccountingService.php` | Posts GL entries for all 3 FD maturity actions |
| `app/Tenant/Modules/Accounting/Contracts/TrialBalanceServiceInterface.php` | Interface: asOfDate, forPeriod, ledgerLines |
| `app/Tenant/Modules/Accounting/Services/TrialBalanceService.php` | Aggregates general_ledger by account for TB report |
| `app/Tenant/Http/Controllers/Api/V1/TrialBalanceController.php` | index() and ledger() endpoints |
| `app/Tenant/Http/Resources/TrialBalanceRowResource.php` | Formats one GL account row |
| `app/Tenant/Http/Resources/LedgerLineResource.php` | Formats one drill-down JE line |
| `tests/Tenant/Accounting/FdMaturityAccountingServiceTest.php` | Unit tests for all 3 JE methods |
| `tests/Tenant/Accounting/TrialBalanceServiceTest.php` | Unit tests for TB service |

### Backend — Modified files
| File | Change |
|------|--------|
| `app/Tenant/Modules/Savings/Services/FixedDepositMaturityService.php` | Inject FdMaturityAccountingServiceInterface; call in rollover, convert, close |
| `app/Providers/AppServiceProvider.php` | Bind both new interfaces |
| `routes/tenant_api.php` | Add 2 TB report routes + import |

### Frontend — New files
| File | Purpose |
|------|---------|
| `src/tenant/modules/reports/pages/TrialBalance.vue` | Report page with mode toggle, table, drill-down drawer |
| `src/tenant/apis/reports/trialBalanceApi.ts` | getTrialBalance() and getLedgerLines() |

### Frontend — Modified files
| File | Change |
|------|--------|
| `src/tenant/modules/reports/routes.ts` | Add trial-balance route |

---

## Task 1: FdMaturityAccountingServiceInterface

**Files:**
- Create: `app/Tenant/Modules/Savings/Contracts/FdMaturityAccountingServiceInterface.php`

- [ ] **Step 1: Create the interface**

```php
<?php

namespace App\Tenant\Modules\Savings\Contracts;

use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

interface FdMaturityAccountingServiceInterface
{
    /**
     * DR 2113 old FD sub-ledger  CR 2113 new FD sub-ledger
     */
    public function postRollover(SavingsAccount $old, SavingsAccount $new, int $actorId): JournalEntry;

    /**
     * DR 2113 Fixed Deposits  CR 2111/2112 target savings account
     */
    public function postPayout(SavingsAccount $fd, SavingsAccount $target, int $actorId): JournalEntry;

    /**
     * DR 2113 Fixed Deposits  CR 2112 Voluntary Savings (reclassification)
     * Must be called BEFORE account_type is updated on $fd.
     */
    public function postConversion(SavingsAccount $fd, int $actorId): JournalEntry;
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Tenant/Modules/Savings/Contracts/FdMaturityAccountingServiceInterface.php
git commit -m "feat: add FdMaturityAccountingServiceInterface"
```

---

## Task 2: FdMaturityAccountingService + Tests

**Files:**
- Create: `app/Tenant/Modules/Savings/Services/FdMaturityAccountingService.php`
- Create: `tests/Tenant/Accounting/FdMaturityAccountingServiceTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Tenant/Accounting/FdMaturityAccountingServiceTest.php

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Savings\Contracts\FdMaturityAccountingServiceInterface;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;

beforeEach(function () {
    $this->fdAccount  = ChartOfAccount::create([
        'gl_code' => '2113', 'name' => 'Fixed Deposits',
        'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit',
        'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true,
    ]);
    $this->volAccount = ChartOfAccount::create([
        'gl_code' => '2112', 'name' => 'Voluntary Savings Deposits',
        'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit',
        'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true,
    ]);
    $this->mandAccount = ChartOfAccount::create([
        'gl_code' => '2111', 'name' => 'Mandatory Savings Deposits',
        'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit',
        'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true,
    ]);

    $fdProduct = SavingsProduct::create([
        'code' => 'FD01', 'name' => 'Fixed Deposit', 'type' => 'fixed', 'status' => 'active',
    ]);
    $volProduct = SavingsProduct::create([
        'code' => 'SAV01', 'name' => 'Regular Savings', 'type' => 'standard', 'status' => 'active',
    ]);

    $this->oldFd = SavingsAccount::create([
        'savings_product_id' => $fdProduct->id,
        'account_no' => 'FD-000001', 'account_type' => 'fixed',
        'balance' => 150000.00, 'status' => 'active', 'member_id' => 1,
    ]);
    $this->newFd = SavingsAccount::create([
        'savings_product_id' => $fdProduct->id,
        'account_no' => 'FD-000002', 'account_type' => 'fixed',
        'balance' => 0.00, 'status' => 'active', 'member_id' => 1,
    ]);
    $this->volSavings = SavingsAccount::create([
        'savings_product_id' => $volProduct->id,
        'account_no' => 'SAV-000001', 'account_type' => 'voluntary',
        'balance' => 5000.00, 'status' => 'active', 'member_id' => 1,
    ]);

    $this->service = app(FdMaturityAccountingServiceInterface::class);
});

it('postRollover posts DR old FD sub-ledger CR new FD sub-ledger', function () {
    $je = $this->service->postRollover($this->oldFd, $this->newFd, 1);

    expect($je)->toBeInstanceOf(JournalEntry::class);
    expect($je->journal_type)->toBe('FD_ROLLOVER');

    $lines = JournalEntryLine::on('tenant')->where('journal_entry_id', $je->id)->get();
    expect($lines)->toHaveCount(2);

    $totalDebit  = $lines->sum('debit');
    $totalCredit = $lines->sum('credit');
    expect(number_format((float) $totalDebit, 2))->toBe(number_format((float) $totalCredit, 2));
    expect((float) $totalDebit)->toBe(150000.0);

    // Both lines post to GL 2113
    $glLines = GeneralLedger::on('tenant')->where('journal_entry_id', $je->id)->get();
    expect($glLines)->toHaveCount(2);
    $glLines->each(fn ($gl) => expect($gl->account_id)->toBe($this->fdAccount->id));
});

it('postPayout posts DR 2113 CR voluntary savings GL', function () {
    $je = $this->service->postPayout($this->oldFd, $this->volSavings, 1);

    expect($je->journal_type)->toBe('FD_PAYOUT');

    $lines = JournalEntryLine::on('tenant')->where('journal_entry_id', $je->id)->get();
    expect($lines)->toHaveCount(2);
    expect(number_format((float) $lines->sum('debit'), 2))
        ->toBe(number_format((float) $lines->sum('credit'), 2));

    $debitLine  = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    expect($debitLine->account_id)->toBe($this->fdAccount->id);
    expect($creditLine->account_id)->toBe($this->volAccount->id);
    expect((float) $debitLine->debit)->toBe(150000.0);
});

it('postConversion posts DR 2113 CR 2112', function () {
    $je = $this->service->postConversion($this->oldFd, 1);

    expect($je->journal_type)->toBe('FD_CONVERSION');

    $lines = JournalEntryLine::on('tenant')->where('journal_entry_id', $je->id)->get();
    expect($lines)->toHaveCount(2);
    expect(number_format((float) $lines->sum('debit'), 2))
        ->toBe(number_format((float) $lines->sum('credit'), 2));

    $debitLine  = $lines->firstWhere('debit', '>', 0);
    $creditLine = $lines->firstWhere('credit', '>', 0);

    expect($debitLine->account_id)->toBe($this->fdAccount->id);
    expect($creditLine->account_id)->toBe($this->volAccount->id);
});
```

- [ ] **Step 2: Run tests — expect FAIL**

```bash
php artisan test tests/Tenant/Accounting/FdMaturityAccountingServiceTest.php --no-coverage
```

Expected: FAIL — `FdMaturityAccountingServiceInterface` not bound.

- [ ] **Step 3: Create FdMaturityAccountingService**

```php
<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Contracts\SavingsCoaResolverInterface;
use App\Tenant\Modules\Accounting\Services\GlPostingEngine;
use App\Tenant\Modules\Accounting\Services\JournalSequenceService;
use App\Tenant\Modules\Savings\Contracts\FdMaturityAccountingServiceInterface;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FdMaturityAccountingService implements FdMaturityAccountingServiceInterface
{
    public function __construct(
        private readonly JournalSequenceService $sequence,
        private readonly GlPostingEngine $gl,
        private readonly SavingsCoaResolverInterface $coa,
    ) {}

    public function postRollover(SavingsAccount $old, SavingsAccount $new, int $actorId): JournalEntry
    {
        $fdGl    = $this->coa->resolveByGlCode('2113');
        $amount  = (float) $old->balance;
        $date    = now()->toDateString();
        $narration = "FD rollover: {$old->account_no} → {$new->account_no}";

        $je = $this->makeJe('FD_ROLLOVER', $old->account_no, $narration, $actorId);

        // DR old FD sub-ledger
        $this->createLine($je, $fdGl, $amount, 0.0, $narration, 1, $date, $old->member_id, $old->id);
        $this->postSubLedger($je, $fdGl, $old->member_id, $amount, 0.0, $date, $narration);

        // CR new FD sub-ledger
        $this->createLine($je, $fdGl, 0.0, $amount, $narration, 2, $date, $new->member_id, $new->id);
        $this->postSubLedger($je, $fdGl, $new->member_id, 0.0, $amount, $date, $narration);

        return $je;
    }

    public function postPayout(SavingsAccount $fd, SavingsAccount $target, int $actorId): JournalEntry
    {
        $fdGl     = $this->coa->resolveByGlCode('2113');
        $targetGl = $this->coa->resolveSavingsLiabilityAccount($target);
        $amount   = (float) $fd->balance;
        $date     = now()->toDateString();
        $narration = "FD payout: {$fd->account_no} → {$target->account_no}";

        $je = $this->makeJe('FD_PAYOUT', $fd->account_no, $narration, $actorId);

        // DR 2113 Fixed Deposits (FD liability closed)
        $this->createLine($je, $fdGl, $amount, 0.0, $narration, 1, $date, $fd->member_id, $fd->id);
        $this->postSubLedger($je, $fdGl, $fd->member_id, $amount, 0.0, $date, $narration);

        // CR target savings account
        $this->createLine($je, $targetGl, 0.0, $amount, $narration, 2, $date, $target->member_id, $target->id);
        $this->postSubLedger($je, $targetGl, $target->member_id, 0.0, $amount, $date, $narration);

        return $je;
    }

    public function postConversion(SavingsAccount $fd, int $actorId): JournalEntry
    {
        // Resolve BEFORE account_type is changed — caller must call this before update()
        $fdGl    = $this->coa->resolveByGlCode('2113');
        $volGl   = $this->coa->resolveByGlCode('2112');
        $amount  = (float) $fd->balance;
        $date    = now()->toDateString();
        $narration = "FD converted to savings: {$fd->account_no}";

        $je = $this->makeJe('FD_CONVERSION', $fd->account_no, $narration, $actorId);

        // DR 2113 Fixed Deposits (reclassify out)
        $this->createLine($je, $fdGl, $amount, 0.0, $narration, 1, $date, $fd->member_id, $fd->id);
        $this->postSubLedger($je, $fdGl, $fd->member_id, $amount, 0.0, $date, $narration);

        // CR 2112 Voluntary Savings (reclassify in)
        $this->createLine($je, $volGl, 0.0, $amount, $narration, 2, $date, $fd->member_id, $fd->id);
        $this->postSubLedger($je, $volGl, $fd->member_id, 0.0, $amount, $date, $narration);

        return $je;
    }

    private function makeJe(string $type, string $reference, string $narration, int $actorId): JournalEntry
    {
        $date = now()->toDateString();

        return JournalEntry::create([
            'entry_no'     => $this->sequence->nextEntryNo('FD'),
            'date'         => $date,
            'period_date'  => $date,
            'fiscal_period'=> substr($date, 0, 7),
            'reference'    => $reference,
            'narration'    => $narration,
            'journal_type' => $type,
            'status'       => 'posted',
            'is_system'    => true,
            'posted_at'    => now(),
            'posted_by'    => $actorId,
        ]);
    }

    private function createLine(
        JournalEntry $je, ChartOfAccount $account,
        float $debit, float $credit,
        string $narration, int $lineNo, string $date,
        ?int $memberId = null, ?int $savingsId = null,
    ): JournalEntryLine {
        $attrs = [
            'journal_entry_id' => $je->id,
            'account_id'       => $account->id,
            'debit'            => $debit,
            'credit'           => $credit,
            'narration'        => $narration,
            'line_no'          => $lineNo,
        ];
        if ($memberId  !== null) $attrs['member_id']  = $memberId;
        if ($savingsId !== null) $attrs['savings_id'] = $savingsId;

        $jel = JournalEntryLine::create($attrs);

        $this->gl->postToGeneralLedger(
            $je->id, $account->id,
            $debit, $credit,
            $date, $narration, $account->normal_balance ?? 'CR',
        );

        return $jel;
    }

    private function postSubLedger(
        JournalEntry $je, ChartOfAccount $account,
        ?int $memberId, float $debit, float $credit,
        string $date, string $narration,
    ): void {
        if (! $memberId) return;

        $this->gl->postToSubLedger(
            $je->id, $account->id,
            $memberId, Member::class,
            $debit, $credit,
            $date, $narration, $account->normal_balance ?? 'CR',
        );
    }
}
```

- [ ] **Step 4: Bind in AppServiceProvider**

Open `app/Providers/AppServiceProvider.php`. Add inside the `register()` method after the last existing `$this->app->bind(...)` block (around line 174):

```php
$this->app->bind(
    \App\Tenant\Modules\Savings\Contracts\FdMaturityAccountingServiceInterface::class,
    \App\Tenant\Modules\Savings\Services\FdMaturityAccountingService::class
);
```

- [ ] **Step 5: Run tests — expect PASS**

```bash
php artisan test tests/Tenant/Accounting/FdMaturityAccountingServiceTest.php --no-coverage
```

Expected: 3 tests, 3 passed.

- [ ] **Step 6: Commit**

```bash
git add app/Tenant/Modules/Savings/Services/FdMaturityAccountingService.php \
        app/Providers/AppServiceProvider.php \
        tests/Tenant/Accounting/FdMaturityAccountingServiceTest.php
git commit -m "feat: add FdMaturityAccountingService — posts JEs for rollover, payout, conversion"
```

---

## Task 3: Wire FdMaturityAccountingService into FixedDepositMaturityService

**Files:**
- Modify: `app/Tenant/Modules/Savings/Services/FixedDepositMaturityService.php`

- [ ] **Step 1: Replace the entire file with the updated version**

```php
<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Savings\Contracts\FdMaturityAccountingServiceInterface;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FixedDepositMaturityService
{
    public function __construct(
        protected FixedDepositCalculator $calculator,
        protected FdMaturityAccountingServiceInterface $accounting,
    ) {}

    public function execute(SavingsAccount $account, int $actorId): void
    {
        $effectiveAction = $account->maturity_action
            ?? $account->savingsProduct?->maturity_action
            ?? 'manual';

        DB::connection('tenant')->transaction(function () use ($account, $effectiveAction, $actorId) {
            match ($effectiveAction) {
                'auto_rollover'      => $this->rollover($account, $actorId),
                'convert_to_savings' => $this->convertToSavings($account, $actorId),
                default              => $this->markMatured($account),
            };
        });
    }

    public function processManualAction(SavingsAccount $account, string $action, int $actorId): void
    {
        DB::connection('tenant')->transaction(function () use ($account, $action, $actorId) {
            match ($action) {
                'rollover' => $this->rollover($account, $actorId),
                'convert'  => $this->convertToSavings($account, $actorId),
                default    => $this->close($account, $actorId),
            };
        });
    }

    private function rollover(SavingsAccount $account, int $actorId): void
    {
        $product = $account->savingsProduct;
        $tenor   = $account->tenor_months ?? $product?->default_tenor_months ?? 6;
        $newRate = (float) ($product?->interest_rate ?? $account->interest_rate ?? 0);
        $now     = Carbon::now();

        $account->update(['status' => 'matured']);

        $payoutType      = $product?->interest_payout_type ?? 'at_maturity';
        $nextInterestDate = $payoutType !== 'at_maturity'
            ? $this->calculator->nextInterestDate($now, $product?->interest_posting_frequency ?? 'monthly')
            : null;

        $newAccount = SavingsAccount::create([
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

        $this->accounting->postRollover($account, $newAccount, $actorId);

        Log::info("FD rolled over: {$account->account_no} closed, {$newAccount->account_no} opened.");
    }

    private function convertToSavings(SavingsAccount $account, int $actorId): void
    {
        $product        = $account->savingsProduct;
        $targetProductId = $product?->convert_to_product_id;

        if (! $targetProductId) {
            Log::warning("FD convert_to_savings: no convert_to_product_id on product {$product?->name}. Falling back to manual.");
            $this->markMatured($account);
            return;
        }

        // Post JE BEFORE updating account_type so the resolver still sees 'fixed' → 2113
        $this->accounting->postConversion($account, $actorId);

        $account->update([
            'account_type'      => 'voluntary',
            'savings_product_id'=> $targetProductId,
            'status'            => 'active',
            'maturity_date'     => null,
            'next_interest_date'=> null,
            'tenor_months'      => null,
        ]);
    }

    private function close(SavingsAccount $account, int $actorId): void
    {
        $targetId = $account->payout_savings_account_id;

        if ($targetId) {
            $target = SavingsAccount::on('tenant')->find($targetId);
            if ($target) {
                $this->accounting->postPayout($account, $target, $actorId);
                $target->increment('balance', $account->balance);
            } else {
                Log::warning("FD close: payout_savings_account_id {$targetId} not found for {$account->account_no}. Skipping JE.");
            }
        } else {
            Log::warning("FD close: no payout_savings_account_id on {$account->account_no}. Skipping payout JE.");
        }

        $account->update(['status' => 'closed', 'balance' => 0]);
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

- [ ] **Step 2: Run full test suite to check for regressions**

```bash
php artisan test --no-coverage
```

Expected: All existing tests pass.

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Savings/Services/FixedDepositMaturityService.php
git commit -m "feat: wire FdMaturityAccountingService into FixedDepositMaturityService"
```

---

## Task 4: TrialBalanceServiceInterface

**Files:**
- Create: `app/Tenant/Modules/Accounting/Contracts/TrialBalanceServiceInterface.php`

- [ ] **Step 1: Create the interface**

```php
<?php

namespace App\Tenant\Modules\Accounting\Contracts;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

interface TrialBalanceServiceInterface
{
    /**
     * Returns all GL accounts with cumulative DR/CR totals up to $date.
     * Opening DR/CR are always 0 in this mode.
     */
    public function asOfDate(Carbon $date): array;

    /**
     * Returns all GL accounts with:
     *  - opening: cumulative DR/CR before $from
     *  - period:  DR/CR movements between $from and $to (inclusive)
     *  - closing: opening + period net
     */
    public function forPeriod(Carbon $from, Carbon $to): array;

    /**
     * Paginated GL lines for a single account within a date range.
     * Includes a running_balance per line.
     */
    public function ledgerLines(int $accountId, Carbon $from, Carbon $to, int $page = 1): LengthAwarePaginator;
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Tenant/Modules/Accounting/Contracts/TrialBalanceServiceInterface.php
git commit -m "feat: add TrialBalanceServiceInterface"
```

---

## Task 5: TrialBalanceService + Tests

**Files:**
- Create: `app/Tenant/Modules/Accounting/Services/TrialBalanceService.php`
- Create: `tests/Tenant/Accounting/TrialBalanceServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/Tenant/Accounting/TrialBalanceServiceTest.php

use App\Tenant\Modules\Accounting\Contracts\TrialBalanceServiceInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->bankAccount = ChartOfAccount::create([
        'gl_code' => '1112', 'name' => 'Cash at Bank',
        'account_type' => 'ASSET', 'account_subtype' => 'Bank',
        'normal_balance' => 'DR', 'level' => 4, 'is_control' => false, 'is_postable' => true,
    ]);
    $this->savingsAccount = ChartOfAccount::create([
        'gl_code' => '2111', 'name' => 'Mandatory Savings',
        'account_type' => 'LIABILITY', 'account_subtype' => 'Member Deposit',
        'normal_balance' => 'CR', 'level' => 4, 'is_control' => false, 'is_postable' => true,
    ]);

    // Seed a JE dated Jan 15
    $je = JournalEntry::create([
        'entry_no' => 'JE-TEST-001', 'date' => '2026-01-15',
        'period_date' => '2026-01-15', 'fiscal_period' => '2026-01',
        'reference' => 'TEST-001', 'narration' => 'Test deposit',
        'journal_type' => 'SAVINGS_DEPOSIT', 'status' => 'posted',
        'is_system' => true, 'posted_at' => now(),
    ]);

    GeneralLedger::create([
        'account_id' => $this->bankAccount->id, 'journal_entry_id' => $je->id,
        'date' => '2026-01-15', 'debit' => 100000, 'credit' => 0, 'balance' => 100000, 'narration' => 'Test',
    ]);
    GeneralLedger::create([
        'account_id' => $this->savingsAccount->id, 'journal_entry_id' => $je->id,
        'date' => '2026-01-15', 'debit' => 0, 'credit' => 100000, 'balance' => 100000, 'narration' => 'Test',
    ]);

    $this->service = app(TrialBalanceServiceInterface::class);
});

it('asOfDate returns accounts with cumulative totals and is balanced', function () {
    $result = $this->service->asOfDate(Carbon::parse('2026-01-31'));

    $accounts = collect($result['accounts']);
    $bankRow  = $accounts->firstWhere('gl_code', '1112');
    $savRow   = $accounts->firstWhere('gl_code', '2111');

    expect($bankRow)->not->toBeNull();
    expect((float) $bankRow['closing_debit'])->toBe(100000.0);
    expect((float) $bankRow['closing_credit'])->toBe(0.0);

    expect($savRow)->not->toBeNull();
    expect((float) $savRow['closing_credit'])->toBe(100000.0);

    expect($result['totals']['is_balanced'])->toBeTrue();
    expect((float) $result['totals']['total_closing_debit'])
        ->toBe((float) $result['totals']['total_closing_credit']);
});

it('forPeriod separates opening from period movements', function () {
    // Add a Feb JE
    $je2 = JournalEntry::create([
        'entry_no' => 'JE-TEST-002', 'date' => '2026-02-10',
        'period_date' => '2026-02-10', 'fiscal_period' => '2026-02',
        'reference' => 'TEST-002', 'narration' => 'Feb deposit',
        'journal_type' => 'SAVINGS_DEPOSIT', 'status' => 'posted',
        'is_system' => true, 'posted_at' => now(),
    ]);
    GeneralLedger::create([
        'account_id' => $this->bankAccount->id, 'journal_entry_id' => $je2->id,
        'date' => '2026-02-10', 'debit' => 50000, 'credit' => 0, 'balance' => 150000, 'narration' => 'Feb',
    ]);
    GeneralLedger::create([
        'account_id' => $this->savingsAccount->id, 'journal_entry_id' => $je2->id,
        'date' => '2026-02-10', 'debit' => 0, 'credit' => 50000, 'balance' => 150000, 'narration' => 'Feb',
    ]);

    $result = $this->service->forPeriod(Carbon::parse('2026-02-01'), Carbon::parse('2026-02-28'));
    $accounts = collect($result['accounts']);
    $bankRow  = $accounts->firstWhere('gl_code', '1112');

    // Opening = Jan movements (100000 DR), Period = Feb movements (50000 DR)
    expect((float) $bankRow['opening_debit'])->toBe(100000.0);
    expect((float) $bankRow['period_debit'])->toBe(50000.0);
    expect((float) $bankRow['closing_debit'])->toBe(150000.0);

    expect($result['totals']['is_balanced'])->toBeTrue();
});

it('ledgerLines returns paginated lines with running balance', function () {
    $paginator = $this->service->ledgerLines(
        $this->bankAccount->id,
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
        1
    );

    expect($paginator->total())->toBe(1);
    $line = $paginator->items()[0];
    expect((float) $line['debit'])->toBe(100000.0);
    expect((float) $line['running_balance'])->toBeGreaterThan(0);
});
```

- [ ] **Step 2: Run — expect FAIL**

```bash
php artisan test tests/Tenant/Accounting/TrialBalanceServiceTest.php --no-coverage
```

Expected: FAIL — `TrialBalanceServiceInterface` not bound.

- [ ] **Step 3: Create TrialBalanceService**

```php
<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Contracts\TrialBalanceServiceInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrialBalanceService implements TrialBalanceServiceInterface
{
    private const PER_PAGE = 50;

    public function asOfDate(Carbon $date): array
    {
        $movements = $this->aggregateGl(null, $date);
        $accounts  = $this->buildRows(new Collection(), $movements);
        $totals    = $this->calcTotals($accounts, 'as_of_date');

        return [
            'mode'         => 'as_of_date',
            'date'         => $date->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'totals'       => $totals,
            'accounts'     => $accounts->values()->all(),
        ];
    }

    public function forPeriod(Carbon $from, Carbon $to): array
    {
        $opening  = $this->aggregateGl(null, $from->copy()->subDay());
        $period   = $this->aggregateGl($from, $to);
        $accounts = $this->buildRows($opening, $period);
        $totals   = $this->calcTotals($accounts, 'period');

        return [
            'mode'         => 'period',
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'totals'       => $totals,
            'accounts'     => $accounts->values()->all(),
        ];
    }

    public function ledgerLines(int $accountId, Carbon $from, Carbon $to, int $page = 1): LengthAwarePaginator
    {
        $query = GeneralLedger::on('tenant')
            ->with('journalEntry')
            ->where('account_id', $accountId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->orderBy('id');

        $total   = $query->count();
        $offset  = ($page - 1) * self::PER_PAGE;
        $rows    = $query->skip($offset)->take(self::PER_PAGE)->get();

        // Compute running balance — start from cumulative balance before $from
        $openingBalance = (string) (GeneralLedger::on('tenant')
            ->where('account_id', $accountId)
            ->where('date', '<', $from->toDateString())
            ->orderByDesc('id')
            ->value('balance') ?? '0');

        $account = ChartOfAccount::on('tenant')->find($accountId);
        $normalBalance = $account?->normal_balance ?? 'DR';
        $running = $openingBalance;

        $items = $rows->map(function ($gl) use (&$running, $normalBalance) {
            $debit  = (string) $gl->debit;
            $credit = (string) $gl->credit;
            $running = $normalBalance === 'DR'
                ? \bcadd($running, \bcsub($debit, $credit, 4), 4)
                : \bcadd($running, \bcsub($credit, $debit, 4), 4);

            $je = $gl->journalEntry;

            return [
                'date'            => $gl->date?->toDateString(),
                'entry_no'        => $je?->entry_no,
                'journal_type'    => $je?->journal_type,
                'description'     => $gl->narration,
                'debit'           => (float) $gl->debit,
                'credit'          => (float) $gl->credit,
                'running_balance' => (float) $running,
                'entity_type'     => null,
                'entity_id'       => null,
            ];
        });

        return new LengthAwarePaginator(
            $items->all(), $total, self::PER_PAGE, $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function aggregateGl(?Carbon $from, Carbon $to): Collection
    {
        $query = DB::connection('tenant')
            ->table('general_ledger')
            ->select('account_id',
                DB::raw('SUM(debit) as total_debit'),
                DB::raw('SUM(credit) as total_credit'))
            ->where('date', '<=', $to->toDateString())
            ->groupBy('account_id');

        if ($from) {
            $query->where('date', '>=', $from->toDateString());
        }

        return $query->get()->keyBy('account_id');
    }

    private function buildRows(Collection $opening, Collection $period): Collection
    {
        $allAccounts = ChartOfAccount::on('tenant')
            ->orderBy('gl_code')
            ->get();

        return $allAccounts->map(function (ChartOfAccount $account) use ($opening, $period) {
            $open = $opening->get($account->id);
            $per  = $period->get($account->id);

            $openDr  = (float) ($open?->total_debit  ?? 0);
            $openCr  = (float) ($open?->total_credit ?? 0);
            $perDr   = (float) ($per?->total_debit   ?? 0);
            $perCr   = (float) ($per?->total_credit  ?? 0);

            $closeDr = $openDr + $perDr;
            $closeCr = $openCr + $perCr;

            return [
                'id'             => $account->id,
                'gl_code'        => $account->gl_code,
                'name'           => $account->name,
                'account_type'   => $account->account_type,
                'account_subtype'=> $account->account_subtype,
                'level'          => $account->level,
                'is_postable'    => (bool) $account->is_postable,
                'opening_debit'  => $openDr,
                'opening_credit' => $openCr,
                'period_debit'   => $perDr,
                'period_credit'  => $perCr,
                'closing_debit'  => $closeDr,
                'closing_credit' => $closeCr,
            ];
        });
    }

    private function calcTotals(Collection $accounts, string $mode): array
    {
        $postable = $accounts->where('is_postable', true);

        $openDr  = $postable->sum('opening_debit');
        $openCr  = $postable->sum('opening_credit');
        $perDr   = $postable->sum('period_debit');
        $perCr   = $postable->sum('period_credit');
        $closeDr = $postable->sum('closing_debit');
        $closeCr = $postable->sum('closing_credit');

        return [
            'total_opening_debit'  => $openDr,
            'total_opening_credit' => $openCr,
            'total_period_debit'   => $perDr,
            'total_period_credit'  => $perCr,
            'total_closing_debit'  => $closeDr,
            'total_closing_credit' => $closeCr,
            'is_balanced'          => number_format($closeDr, 2) === number_format($closeCr, 2),
        ];
    }
}
```

- [ ] **Step 4: Bind in AppServiceProvider** — add after the FdMaturityAccountingService binding:

```php
$this->app->bind(
    \App\Tenant\Modules\Accounting\Contracts\TrialBalanceServiceInterface::class,
    \App\Tenant\Modules\Accounting\Services\TrialBalanceService::class
);
```

- [ ] **Step 5: Run tests — expect PASS**

```bash
php artisan test tests/Tenant/Accounting/TrialBalanceServiceTest.php --no-coverage
```

Expected: 3 tests, 3 passed.

- [ ] **Step 6: Commit**

```bash
git add app/Tenant/Modules/Accounting/Contracts/TrialBalanceServiceInterface.php \
        app/Tenant/Modules/Accounting/Services/TrialBalanceService.php \
        app/Providers/AppServiceProvider.php \
        tests/Tenant/Accounting/TrialBalanceServiceTest.php
git commit -m "feat: add TrialBalanceService — as-of-date, period, and ledger drill-down"
```

---

## Task 6: TrialBalanceController + Resources + Routes

**Files:**
- Create: `app/Tenant/Http/Controllers/Api/V1/TrialBalanceController.php`
- Create: `app/Tenant/Http/Resources/TrialBalanceRowResource.php`
- Create: `app/Tenant/Http/Resources/LedgerLineResource.php`
- Modify: `routes/tenant_api.php`

- [ ] **Step 1: Create TrialBalanceRowResource**

```php
<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrialBalanceRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this['id'],
            'gl_code'         => $this['gl_code'],
            'name'            => $this['name'],
            'account_type'    => $this['account_type'],
            'account_subtype' => $this['account_subtype'],
            'level'           => $this['level'],
            'is_postable'     => $this['is_postable'],
            'opening_debit'   => round($this['opening_debit'],  2),
            'opening_credit'  => round($this['opening_credit'], 2),
            'period_debit'    => round($this['period_debit'],   2),
            'period_credit'   => round($this['period_credit'],  2),
            'closing_debit'   => round($this['closing_debit'],  2),
            'closing_credit'  => round($this['closing_credit'], 2),
        ];
    }
}
```

- [ ] **Step 2: Create LedgerLineResource**

```php
<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'date'            => $this['date'],
            'entry_no'        => $this['entry_no'],
            'journal_type'    => $this['journal_type'],
            'description'     => $this['description'],
            'debit'           => round($this['debit'],           2),
            'credit'          => round($this['credit'],          2),
            'running_balance' => round($this['running_balance'], 2),
            'entity_type'     => $this['entity_type'],
            'entity_id'       => $this['entity_id'],
        ];
    }
}
```

- [ ] **Step 3: Create TrialBalanceController**

```php
<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Accounting\Contracts\TrialBalanceServiceInterface;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrialBalanceController extends Controller
{
    public function __construct(
        private readonly TrialBalanceServiceInterface $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date'],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to'   => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
        ]);

        if ($request->filled('from') && $request->filled('to')) {
            $result = $this->service->forPeriod(
                Carbon::parse($request->input('from')),
                Carbon::parse($request->input('to')),
            );
        } else {
            $date = $request->filled('date')
                ? Carbon::parse($request->input('date'))
                : Carbon::today();

            $result = $this->service->asOfDate($date);
        }

        return response()->json($result);
    }

    public function ledger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'from'       => ['required', 'date'],
            'to'         => ['required', 'date', 'after_or_equal:from'],
            'page'       => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $this->service->ledgerLines(
            (int)   $validated['account_id'],
            Carbon::parse($validated['from']),
            Carbon::parse($validated['to']),
            (int) ($validated['page'] ?? 1),
        );

        return response()->json([
            'data'         => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
        ]);
    }
}
```

- [ ] **Step 4: Register routes in tenant_api.php**

Add the import at the top of `routes/tenant_api.php` with the other controller imports:
```php
use App\Tenant\Http\Controllers\Api\V1\TrialBalanceController;
```

Add routes after the existing `reports/loan-arrears` block (around line 108):
```php
Route::get('reports/trial-balance', [TrialBalanceController::class, 'index']);
Route::get('reports/trial-balance/ledger', [TrialBalanceController::class, 'ledger']);
```

- [ ] **Step 5: Smoke-test the endpoint**

```bash
php artisan route:list | grep trial-balance
```

Expected output:
```
GET  api/v1/tenant/reports/trial-balance         TrialBalanceController@index
GET  api/v1/tenant/reports/trial-balance/ledger  TrialBalanceController@ledger
```

- [ ] **Step 6: Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/TrialBalanceController.php \
        app/Tenant/Http/Resources/TrialBalanceRowResource.php \
        app/Tenant/Http/Resources/LedgerLineResource.php \
        routes/tenant_api.php
git commit -m "feat: add TrialBalanceController with index and ledger drill-down endpoints"
```

---

## Task 7: Frontend — trialBalanceApi.ts

**Files:**
- Create: `src/tenant/apis/reports/trialBalanceApi.ts`

- [ ] **Step 1: Create the API module**

```typescript
// src/tenant/apis/reports/trialBalanceApi.ts
import { tenantClient } from '@/tenant/apis/tenantClient'

export interface TrialBalanceParams {
  date?: string
  from?: string
  to?: string
}

export interface LedgerParams {
  account_id: number
  from: string
  to: string
  page?: number
}

export const trialBalanceApi = {
  async getTrialBalance(params: TrialBalanceParams) {
    const res = await tenantClient.get('/reports/trial-balance', { params })
    return res.data
  },

  async getLedgerLines(params: LedgerParams) {
    const res = await tenantClient.get('/reports/trial-balance/ledger', { params })
    return res.data
  },
}
```

- [ ] **Step 2: Commit**

```bash
git add src/tenant/apis/reports/trialBalanceApi.ts
git commit -m "feat: add trialBalanceApi — getTrialBalance and getLedgerLines"
```

---

## Task 8: Frontend — TrialBalance.vue page

**Files:**
- Create: `src/tenant/modules/reports/pages/TrialBalance.vue`

- [ ] **Step 1: Create the page**

```vue
<script setup lang="ts">
import { ref, computed } from 'vue'
import { Scale, ChevronDown, ChevronRight, X, AlertTriangle, CheckCircle } from 'lucide-vue-next'
import { Spinner, formatMoneyValue } from '@/Global'
import { trialBalanceApi } from '@/tenant/apis/reports/trialBalanceApi'

// ── State ─────────────────────────────────────────────────────────────────────
const mode        = ref<'as_of_date' | 'period'>('as_of_date')
const asOfDate    = ref(new Date().toISOString().split('T')[0])
const periodFrom  = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0])
const periodTo    = ref(new Date().toISOString().split('T')[0])
const loading     = ref(false)
const result      = ref<any>(null)

// Drill-down drawer
const drawerOpen    = ref(false)
const drawerAccount = ref<any>(null)
const drawerLines   = ref<any[]>([])
const drawerPage    = ref(1)
const drawerTotal   = ref(0)
const drawerLastPage = ref(1)
const drawerLoading = ref(false)

// ── Computed ──────────────────────────────────────────────────────────────────
const accounts = computed(() => result.value?.accounts ?? [])
const totals   = computed(() => result.value?.totals ?? null)

const isBalanced = computed(() => totals.value?.is_balanced === true)

const drFrom = computed(() => result.value?.from ?? result.value?.date ?? asOfDate.value)
const drTo   = computed(() => result.value?.to   ?? result.value?.date ?? asOfDate.value)

// ── Actions ───────────────────────────────────────────────────────────────────
async function generate() {
  loading.value = true
  result.value  = null
  try {
    if (mode.value === 'period') {
      result.value = await trialBalanceApi.getTrialBalance({ from: periodFrom.value, to: periodTo.value })
    } else {
      result.value = await trialBalanceApi.getTrialBalance({ date: asOfDate.value })
    }
  } finally {
    loading.value = false
  }
}

async function openDrillDown(account: any, side: 'debit' | 'credit') {
  if (!account.is_postable) return
  const amount = side === 'debit'
    ? (mode.value === 'period' ? account.period_debit : account.closing_debit)
    : (mode.value === 'period' ? account.period_credit : account.closing_credit)
  if (!amount) return

  drawerAccount.value = account
  drawerPage.value    = 1
  drawerOpen.value    = true
  await fetchDrillDown()
}

async function fetchDrillDown() {
  if (!drawerAccount.value) return
  drawerLoading.value = true
  try {
    const res = await trialBalanceApi.getLedgerLines({
      account_id: drawerAccount.value.id,
      from: drFrom.value,
      to:   drTo.value,
      page: drawerPage.value,
    })
    drawerLines.value    = res.data
    drawerTotal.value    = res.total
    drawerLastPage.value = res.last_page
  } finally {
    drawerLoading.value = false
  }
}

async function loadMore() {
  drawerPage.value++
  await fetchDrillDown()
}

function fmt(v: number) { return formatMoneyValue(v ?? 0) }
function fmtCell(v: number) { return v ? formatMoneyValue(v) : '—' }

function typeColor(type: string) {
  const map: Record<string, string> = {
    ASSET: 'text-blue-600', LIABILITY: 'text-orange-600',
    EQUITY: 'text-purple-600', INCOME: 'text-green-600', EXPENSE: 'text-red-600',
  }
  return map[type] ?? 'text-neutral-500'
}
</script>

<template>
  <div class="flex flex-col gap-6 p-6">

    <!-- Header -->
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-nfuko-primary/10 flex items-center justify-center">
          <Scale class="w-5 h-5 text-nfuko-primary" />
        </div>
        <div>
          <h1 class="text-2xl font-bold text-neutral-900 dark:text-white">Trial Balance</h1>
          <p class="text-sm text-neutral-500">Verify that total debits equal total credits across all accounts.</p>
        </div>
      </div>
    </div>

    <!-- Controls -->
    <div class="flex flex-wrap items-end gap-4 rounded-2xl border border-neutral-100 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
      <!-- Mode toggle -->
      <div class="flex p-1 bg-neutral-100 dark:bg-neutral-800 rounded-lg gap-1">
        <button
          v-for="m in [{ key: 'as_of_date', label: 'As of Date' }, { key: 'period', label: 'Period' }]"
          :key="m.key"
          @click="mode = m.key as any"
          :class="['px-4 py-1.5 text-xs font-bold rounded-md transition-all', mode === m.key ? 'bg-nfuko-primary text-white shadow-sm' : 'text-neutral-400 hover:text-neutral-600']"
        >{{ m.label }}</button>
      </div>

      <!-- As-of-date input -->
      <div v-if="mode === 'as_of_date'" class="flex flex-col gap-1">
        <label class="text-xs text-neutral-400">As at</label>
        <input v-model="asOfDate" type="date"
          class="rounded-lg border border-neutral-200 bg-white py-2 px-3 text-sm outline-none focus:border-nfuko-primary dark:border-neutral-700 dark:bg-neutral-800 dark:text-white" />
      </div>

      <!-- Period inputs -->
      <template v-else>
        <div class="flex flex-col gap-1">
          <label class="text-xs text-neutral-400">From</label>
          <input v-model="periodFrom" type="date"
            class="rounded-lg border border-neutral-200 bg-white py-2 px-3 text-sm outline-none focus:border-nfuko-primary dark:border-neutral-700 dark:bg-neutral-800 dark:text-white" />
        </div>
        <div class="flex flex-col gap-1">
          <label class="text-xs text-neutral-400">To</label>
          <input v-model="periodTo" type="date"
            class="rounded-lg border border-neutral-200 bg-white py-2 px-3 text-sm outline-none focus:border-nfuko-primary dark:border-neutral-700 dark:bg-neutral-800 dark:text-white" />
        </div>
      </template>

      <button @click="generate"
        class="rounded-full bg-nfuko-primary px-6 py-2 text-sm font-semibold text-white transition hover:bg-nfuko-primary/90 shadow-sm">
        Generate
      </button>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <Spinner class="h-8 w-8 text-nfuko-primary" />
    </div>

    <template v-if="result && !loading">
      <!-- Balance indicator -->
      <div :class="['flex items-center gap-3 rounded-2xl border p-4',
        isBalanced
          ? 'bg-emerald-50 border-emerald-200 dark:bg-emerald-900/20 dark:border-emerald-800'
          : 'bg-rose-50 border-rose-200 dark:bg-rose-900/20 dark:border-rose-800']">
        <CheckCircle v-if="isBalanced" class="w-5 h-5 text-emerald-600 flex-shrink-0" />
        <AlertTriangle v-else class="w-5 h-5 text-rose-600 flex-shrink-0" />
        <div>
          <p v-if="isBalanced" class="text-sm font-bold text-emerald-800 dark:text-emerald-300">
            Books are balanced — Total DR {{ fmt(totals.total_closing_debit) }} = Total CR {{ fmt(totals.total_closing_credit) }}
          </p>
          <p v-else class="text-sm font-bold text-rose-800 dark:text-rose-300">
            Out of balance by {{ fmt(Math.abs(totals.total_closing_debit - totals.total_closing_credit)) }} — investigate unposted transactions
          </p>
        </div>
      </div>

      <!-- Table -->
      <div class="overflow-x-auto rounded-2xl border border-neutral-100 dark:border-neutral-800 shadow-sm">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 dark:bg-neutral-800 text-xs font-bold uppercase tracking-wider text-neutral-500">
            <tr>
              <th class="px-4 py-3 text-left w-24">GL Code</th>
              <th class="px-4 py-3 text-left">Account Name</th>
              <th class="px-4 py-3 text-left w-24">Type</th>
              <template v-if="mode === 'period'">
                <th class="px-4 py-3 text-right">Opening DR</th>
                <th class="px-4 py-3 text-right">Opening CR</th>
                <th class="px-4 py-3 text-right">Period DR</th>
                <th class="px-4 py-3 text-right">Period CR</th>
              </template>
              <th class="px-4 py-3 text-right">Closing DR</th>
              <th class="px-4 py-3 text-right">Closing CR</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
            <template v-for="account in accounts" :key="account.id">
              <!-- Header accounts — section dividers -->
              <tr v-if="!account.is_postable"
                class="bg-neutral-50 dark:bg-neutral-800/60">
                <td class="px-4 py-2 font-black text-xs text-neutral-500">{{ account.gl_code }}</td>
                <td :colspan="mode === 'period' ? 7 : 3" class="px-4 py-2 font-black text-xs text-neutral-700 dark:text-neutral-300 uppercase tracking-wider">
                  {{ account.name }}
                </td>
              </tr>
              <!-- Postable accounts -->
              <tr v-else class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40 transition-colors">
                <td class="px-4 py-2.5 font-mono text-xs text-neutral-400">{{ account.gl_code }}</td>
                <td class="px-4 py-2.5 text-neutral-800 dark:text-neutral-200">
                  <span :class="typeColor(account.account_type)">{{ account.name }}</span>
                </td>
                <td class="px-4 py-2.5">
                  <span class="text-[10px] font-bold uppercase tracking-wide text-neutral-400">{{ account.account_subtype }}</span>
                </td>
                <template v-if="mode === 'period'">
                  <td class="px-4 py-2.5 text-right text-neutral-500 text-xs">{{ fmtCell(account.opening_debit) }}</td>
                  <td class="px-4 py-2.5 text-right text-neutral-500 text-xs">{{ fmtCell(account.opening_credit) }}</td>
                  <td @click="openDrillDown(account, 'debit')"
                    :class="['px-4 py-2.5 text-right text-xs', account.period_debit ? 'cursor-pointer font-bold text-blue-600 hover:text-blue-800 hover:underline' : 'text-neutral-400']">
                    {{ fmtCell(account.period_debit) }}
                  </td>
                  <td @click="openDrillDown(account, 'credit')"
                    :class="['px-4 py-2.5 text-right text-xs', account.period_credit ? 'cursor-pointer font-bold text-orange-600 hover:text-orange-800 hover:underline' : 'text-neutral-400']">
                    {{ fmtCell(account.period_credit) }}
                  </td>
                </template>
                <td @click="openDrillDown(account, 'debit')"
                  :class="['px-4 py-2.5 text-right font-semibold', account.closing_debit ? 'cursor-pointer text-blue-700 hover:text-blue-900 hover:underline' : 'text-neutral-300']">
                  {{ fmtCell(account.closing_debit) }}
                </td>
                <td @click="openDrillDown(account, 'credit')"
                  :class="['px-4 py-2.5 text-right font-semibold', account.closing_credit ? 'cursor-pointer text-orange-700 hover:text-orange-900 hover:underline' : 'text-neutral-300']">
                  {{ fmtCell(account.closing_credit) }}
                </td>
              </tr>
            </template>

            <!-- Totals row -->
            <tr v-if="totals" class="bg-nfuko-primary/5 font-black text-sm border-t-2 border-nfuko-primary/20">
              <td class="px-4 py-3" colspan="3">TOTAL</td>
              <template v-if="mode === 'period'">
                <td class="px-4 py-3 text-right">{{ fmt(totals.total_opening_debit) }}</td>
                <td class="px-4 py-3 text-right">{{ fmt(totals.total_opening_credit) }}</td>
                <td class="px-4 py-3 text-right">{{ fmt(totals.total_period_debit) }}</td>
                <td class="px-4 py-3 text-right">{{ fmt(totals.total_period_credit) }}</td>
              </template>
              <td class="px-4 py-3 text-right text-blue-700">{{ fmt(totals.total_closing_debit) }}</td>
              <td class="px-4 py-3 text-right text-orange-700">{{ fmt(totals.total_closing_credit) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Drill-down drawer -->
    <Teleport to="body">
      <div v-if="drawerOpen" class="fixed inset-0 z-50 flex justify-end" @click.self="drawerOpen = false">
        <div class="h-full w-full max-w-xl bg-white dark:bg-neutral-900 shadow-2xl flex flex-col">
          <!-- Drawer header -->
          <div class="flex items-center justify-between px-6 py-4 border-b border-neutral-100 dark:border-neutral-800">
            <div>
              <p class="text-xs font-mono text-neutral-400">{{ drawerAccount?.gl_code }}</p>
              <p class="font-bold text-neutral-900 dark:text-white">{{ drawerAccount?.name }}</p>
              <p class="text-xs text-neutral-400 mt-0.5">{{ drFrom }} → {{ drTo }}</p>
            </div>
            <button @click="drawerOpen = false" class="p-2 rounded-full hover:bg-neutral-100 dark:hover:bg-neutral-800">
              <X class="w-4 h-4 text-neutral-500" />
            </button>
          </div>

          <!-- Drawer body -->
          <div class="flex-1 overflow-y-auto">
            <div v-if="drawerLoading" class="flex items-center justify-center py-12">
              <Spinner class="h-6 w-6 text-nfuko-primary" />
            </div>
            <table v-else class="w-full text-xs">
              <thead class="sticky top-0 bg-neutral-50 dark:bg-neutral-800 font-bold uppercase tracking-wider text-neutral-400">
                <tr>
                  <th class="px-4 py-3 text-left">Date</th>
                  <th class="px-4 py-3 text-left">Reference</th>
                  <th class="px-4 py-3 text-right">Debit</th>
                  <th class="px-4 py-3 text-right">Credit</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                <tr v-for="(line, i) in drawerLines" :key="i"
                  class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40 transition group">
                  <td class="px-4 py-2.5 text-neutral-500">{{ line.date }}</td>
                  <td class="px-4 py-2.5">
                    <div class="font-mono text-neutral-700 dark:text-neutral-300">{{ line.entry_no }}</div>
                    <div class="text-neutral-400 truncate max-w-[180px]">{{ line.description }}</div>
                  </td>
                  <td class="px-4 py-2.5 text-right text-blue-600 font-semibold">{{ line.debit ? fmt(line.debit) : '—' }}</td>
                  <td class="px-4 py-2.5 text-right text-orange-600 font-semibold">{{ line.credit ? fmt(line.credit) : '—' }}</td>
                </tr>
                <tr v-if="!drawerLines.length && !drawerLoading">
                  <td colspan="4" class="px-4 py-12 text-center text-neutral-400">No transactions in this period.</td>
                </tr>
              </tbody>
            </table>

            <!-- Load more -->
            <div v-if="drawerPage < drawerLastPage" class="p-4 text-center">
              <button @click="loadMore" class="text-xs font-bold text-nfuko-primary hover:underline">
                Load more — page {{ drawerPage + 1 }} of {{ drawerLastPage }}
              </button>
            </div>
          </div>

          <!-- Footer: running balance -->
          <div class="px-6 py-3 border-t border-neutral-100 dark:border-neutral-800 text-xs text-neutral-500">
            {{ drawerTotal }} transaction{{ drawerTotal !== 1 ? 's' : '' }}
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add src/tenant/modules/reports/pages/TrialBalance.vue
git commit -m "feat: add TrialBalance report page with mode toggle, table, and drill-down drawer"
```

---

## Task 9: Frontend — Register route + nav link

**Files:**
- Modify: `src/tenant/modules/reports/routes.ts`

- [ ] **Step 1: Add the route**

Open `src/tenant/modules/reports/routes.ts`. Add import and route entry:

```typescript
import type { RouteRecordRaw } from 'vue-router'
import Reports from './pages/Reports.vue'
import MemberStatement from './pages/MemberStatement.vue'
import BalancesReport from './pages/BalancesReport.vue'
import TrialBalance from './pages/TrialBalance.vue'

export const reportsRoutes: RouteRecordRaw[] = [
  {
    path: 'reports',
    name: 'tenant-reports',
    component: Reports,
  },
  {
    path: 'reports/member-statement/:member_id?',
    name: 'tenant-member-statement',
    component: MemberStatement,
  },
  {
    path: 'reports/balances',
    name: 'tenant-balances-report',
    component: BalancesReport,
  },
  {
    path: 'reports/trial-balance',
    name: 'tenant-trial-balance',
    component: TrialBalance,
  },
]
```

- [ ] **Step 2: Add nav link to Reports landing page**

Open `src/tenant/modules/reports/pages/Reports.vue`. Find the existing report card grid and add a Trial Balance card alongside the others. Look for the section with report links (search for `balances` or card elements). Add:

```html
<RouterLink to="/tenant/reports/trial-balance"
  class="flex items-center gap-4 rounded-2xl border border-neutral-100 bg-white p-5 shadow-sm transition hover:shadow-md hover:border-nfuko-primary/30 dark:border-neutral-800 dark:bg-neutral-900">
  <div class="w-10 h-10 rounded-xl bg-nfuko-primary/10 flex items-center justify-center flex-shrink-0">
    <Scale class="w-5 h-5 text-nfuko-primary" />
  </div>
  <div>
    <p class="font-bold text-neutral-900 dark:text-white text-sm">Trial Balance</p>
    <p class="text-xs text-neutral-500 mt-0.5">Verify DR = CR across all GL accounts.</p>
  </div>
</RouterLink>
```

Add `Scale` to the lucide import at the top of `Reports.vue`:
```typescript
import { Calendar, Filter, Scale } from 'lucide-vue-next'
```

- [ ] **Step 3: Run the frontend dev server and verify**

```bash
pnpm dev
```

Navigate to `/tenant/reports/trial-balance`. Verify:
- Mode toggle switches between As of Date and Period
- Generate button fetches and renders the table
- Clicking a non-zero DR or CR cell opens the drill-down drawer
- Balance indicator shows green when DR = CR

- [ ] **Step 4: Commit**

```bash
git add src/tenant/modules/reports/routes.ts \
        src/tenant/modules/reports/pages/Reports.vue
git commit -m "feat: register trial-balance route and add nav card to Reports landing page"
```

---

## Task 10: Run full test suite and push

- [ ] **Step 1: Run all backend tests**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
php artisan test --no-coverage
```

Expected: All tests pass including the 6 new tests.

- [ ] **Step 2: Run frontend type check**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
pnpm type-check
```

Expected: No type errors.

- [ ] **Step 3: Push both branches**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
git push origin fix/expense-module-may-09-2026

cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
git push origin fix/expense-module-may-09-2026
```
