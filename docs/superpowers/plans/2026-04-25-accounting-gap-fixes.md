# Accounting Gap Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix two missing journal-entry gaps that prevent the trial balance from balancing — topup loan disbursement has no GL entry, and share purchases have no GL entry.

**Architecture:** Gap 1 adds a `postTopupDisbursementEntry()` public method to `LoanDisbursementService`, called from `LoanTopupService::executeExpressFlow()` after the new loan is created. Gap 2 adds a new `ShareAccountingService` and wires it into `MemberController::store()` after `Share::create()`. Both gaps follow the identical double-entry pattern used throughout the codebase (JournalEntry + JournalEntryLine + GeneralLedger + SubLedger). Gap 3 (penalty accrual) was found to already be implemented in `LoanPenaltyCalculatorService::postPenaltyAccrual()` — no action needed.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL, Pest, `tenant` DB connection for all accounting models.

---

## Context for implementors

### Double-entry pattern used everywhere in this codebase

Every accounting event creates four records:
1. `journal_entries` row (the header)
2. `journal_entry_lines` rows (one per DR/CR leg)
3. `general_ledger` rows (one per line, running balance per account)
4. `sub_ledger` rows (one per line, running balance per account+entity)

`GeneralLedger` and `SubLedger` both maintain a running `balance` column. The balance formula:
- For DR-normal accounts: `lastBalance + debit - credit`
- For CR-normal accounts: `lastBalance + credit - debit`

### GL codes used
- `1111` — Petty Cash (ASSET, DR-normal, postable) — DR for cash-received events
- `3110` — Ordinary Share Capital (EQUITY, CR-normal, postable) — CR for share purchases
- `loan_portfolio_account_id` (on loan product) — DR for loan disbursements
- `disbursement_account_id` (on loan product) — CR for loan disbursements

### Key models (all use `tenant` connection)
- `App\Tenant\Modules\Accounting\Models\JournalEntry`
- `App\Tenant\Modules\Accounting\Models\JournalEntryLine`
- `App\Tenant\Modules\Accounting\Models\GeneralLedger`
- `App\Tenant\Modules\Accounting\Models\SubLedger`
- `App\Tenant\Modules\Accounting\Models\ChartOfAccount`
- `App\Tenant\Modules\Shares\Models\Share`
- `App\Tenant\Modules\Loans\Models\Loan`

### CLAUDE.md rules to follow
- Every backend feature: Model → Interface → Service → FormRequest → Resource → Controller
- No function/method > 200 lines
- No class file > 400 lines
- Inject interfaces, never concrete classes in controllers

---

## Task 1: Gap 1 — Topup Disbursement Journal Entry

**Files:**
- Modify: `app/Tenant/Modules/Loans/Contracts/LoanDisbursementServiceInterface.php`
- Modify: `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php`
- Modify: `app/Tenant/Modules/Loans/Services/LoanTopupService.php`
- Create: `tests/Feature/Tenant/Loans/LoanTopupAccountingTest.php`

### What the bug is

`LoanTopupService::executeExpressFlow()` (line ~95 of `LoanTopupService.php`) creates a new `Loan` record marked as `Disbursed` and then calls `$this->disbursementService->regenerateSchedule(...)`. It never posts any journal entry for the new loan. The new loan principal exits the disbursement account in real life, but the ledger never records it.

**Missing entry:**
```
DR  Loan Portfolio Account (product->loan_portfolio_account_id)  = $newLoanTotal
CR  Disbursement Account   (product->disbursement_account_id)    = $newLoanTotal
```

### Why we can't just call `disburse()` on the existing service

`LoanDisbursementService::disburse()` requires a `LoanApplication` record (it asserts approved status, links back to the application, transitions statuses). The topup express flow skips the application entirely. We need a focused public method just for the JE.

### Why we need to change `private` to `protected`

The existing `line()` and `postJournalEntry()` helpers in `LoanDisbursementService` are `private`. The new public method lives in the same class so it can call them directly — no visibility change needed for those. We only need to add one new `public` method.

---

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tenant/Loans/LoanTopupAccountingTest.php`:

```php
<?php

namespace Tests\Feature\Tenant\Loans;

use App\Domain\Tenancy\Entities\Tenant;
use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Services\LoanTopupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoanTopupAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.central_domain', 'admin.mfukopro.test');
        $testDb = config('database.connections.mysql.database');
        Config::set('database.connections.master.database', $testDb);
        Config::set('database.connections.tenant.database', $testDb);

        DB::purge('master');
        DB::purge('tenant');

        $this->artisan('migrate', [
            '--path' => 'database/migrations/tenant',
            '--database' => 'mysql',
        ]);

        $this->tenant = Tenant::create([
            'id' => 'test-tenant',
            'name' => 'Test Sacco',
            'subdomain' => 'test',
            'database_name' => $testDb,
            'status' => 'active',
        ]);

        $this->staff = Staff::factory()->create();
    }

    public function test_express_topup_posts_disbursement_journal_entry(): void
    {
        // Arrange: GL accounts
        $portfolioGl = ChartOfAccount::create([
            'gl_code' => '1310', 'name' => 'Loan Portfolio', 'account_type' => 'ASSET',
            'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        $disbGl = ChartOfAccount::create([
            'gl_code' => '1121', 'name' => 'Disbursement Account', 'account_type' => 'ASSET',
            'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        $interestIncomeGl = ChartOfAccount::create([
            'gl_code' => '4110', 'name' => 'Interest Income', 'account_type' => 'INCOME',
            'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        $interestReceivableGl = ChartOfAccount::create([
            'gl_code' => '1320', 'name' => 'Interest Receivable', 'account_type' => 'ASSET',
            'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        $penaltyIncomeGl = ChartOfAccount::create([
            'gl_code' => '4130', 'name' => 'Penalty Income', 'account_type' => 'INCOME',
            'normal_balance' => 'CR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        $penaltyReceivableGl = ChartOfAccount::create([
            'gl_code' => '1330', 'name' => 'Penalty Receivable', 'account_type' => 'ASSET',
            'normal_balance' => 'DR', 'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);

        // Member (no factory exists — create directly)
        $member = \App\Models\Member::create([
            'name' => 'Test Member',
            'member_number' => 'MBR-TEST-001',
            'status' => 'active',
            'phone' => '0700000000',
        ]);

        // Loan product with required GL mappings (no factory — create directly)
        $product = LoanProduct::create([
            'code' => 'TP01',
            'name' => 'Test Product',
            'interest_rate' => 12,
            'interest_method' => 'reducing_balance',
            'repayment_structure' => 'equal_installment',
            'repayment_cycle' => 'monthly',
            'interest_period' => 'monthly',
            'loan_duration' => 12,
            'duration_type' => 'months',
            'is_active' => true,
            'topup_auto_disbursement' => true,
            'loan_portfolio_account_id' => $portfolioGl->id,
            'disbursement_account_id' => $disbGl->id,
            'interest_income_account_id' => $interestIncomeGl->id,
            'interest_receivable_account_id' => $interestReceivableGl->id,
            'penalty_income_account_id' => $penaltyIncomeGl->id,
            'penalty_receivable_account_id' => $penaltyReceivableGl->id,
        ]);

        // Existing loan to topup (no factory — create directly)
        $existingLoan = Loan::create([
            'loan_no' => 'LN-TEST-001',
            'member_id' => $member->id,
            'loan_product_id' => $product->id,
            'principal' => 50000,
            'outstanding_balance' => 30000,
            'status' => LoanStatus::Active,
            'interest_rate' => 12,
            'term_months' => 12,
            'disbursed_at' => now(),
            'schedule_date' => now(),
        ]);

        $jeCountBefore = JournalEntry::on('tenant')->count();

        // Act
        $service = app(LoanTopupService::class);
        $result = $service->execute(
            referenceLoan: $existingLoan,
            freshCashAmount: 20000,
            requestedTerm: 12,
            topupType: 'consolidated',
            staffId: $this->staff->id,
        );

        // Assert: a disbursement JE was posted for the new loan
        $this->assertGreaterThan($jeCountBefore, JournalEntry::on('tenant')->count());

        $newLoan = Loan::on('tenant')->find($result['new_loan_id']);
        $this->assertNotNull($newLoan);

        $disbJe = JournalEntry::on('tenant')
            ->where('reference', $newLoan->loan_no)
            ->where('reference_type', 'loan')
            ->whereRaw("entry_no LIKE 'JE-LOAN_DISB%'")
            ->first();

        $this->assertNotNull($disbJe, 'Expected a LOAN_DISB journal entry for the new topup loan');

        $lines = $disbJe->lines()->get();
        $this->assertCount(2, $lines);

        $drLine = $lines->firstWhere('account_id', $portfolioGl->id);
        $crLine = $lines->firstWhere('account_id', $disbGl->id);

        $this->assertNotNull($drLine);
        $this->assertNotNull($crLine);
        $this->assertEquals((float) $newLoan->principal, (float) $drLine->debit);
        $this->assertEquals((float) $newLoan->principal, (float) $crLine->credit);
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
php artisan test tests/Feature/Tenant/Loans/LoanTopupAccountingTest.php --filter=test_express_topup_posts_disbursement_journal_entry -v
```

Expected: FAIL — "Expected a LOAN_DISB journal entry for the new topup loan" assertion fails because `executeExpressFlow()` never posts a JE.

- [ ] **Step 3: Add `postTopupDisbursementEntry` to the interface**

Open `app/Tenant/Modules/Loans/Contracts/LoanDisbursementServiceInterface.php`.

Add this method signature (after the existing `regenerateSchedule` signature):

```php
public function postTopupDisbursementEntry(Loan $newLoan, ?int $actorId): void;
```

The file currently has:
```php
public function regenerateSchedule(Loan $loan, Carbon $newScheduleDate): void;
```

Add the new line after it. Ensure `Loan` is already imported — it should be since `disburse()` returns `Loan`.

- [ ] **Step 4: Add the public method to `LoanDisbursementService`**

Open `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php`.

Add the following method anywhere in the public section, after the `regenerateSchedule()` method (around line 268):

```php
/**
 * Post the disbursement journal entry for a top-up loan created via the
 * express (auto-disburse) flow. The standard disburse() path cannot be
 * used here because no LoanApplication exists for the topup.
 *
 * DR  Loan Portfolio Account  = principal
 * CR  Disbursement Account    = principal
 */
public function postTopupDisbursementEntry(Loan $newLoan, ?int $actorId): void
{
    $product = $newLoan->loanProduct()->firstOrFail();

    if (! $product->loan_portfolio_account_id || ! $product->disbursement_account_id) {
        return;
    }

    $principal = (float) $newLoan->principal;
    $narration = "Top-up loan disbursement – {$newLoan->loan_no}";

    $lines = [
        $this->line($product->loan_portfolio_account_id, $principal, 0.0, $narration, $newLoan->id),
        $this->line($product->disbursement_account_id, 0.0, $principal, $narration, $newLoan->id),
    ];

    $this->postJournalEntry($newLoan, 'LOAN_DISB', $narration, $lines, $actorId);
}
```

> Note: `$this->line()` and `$this->postJournalEntry()` are private methods on the same class — they are callable here without any visibility change.

- [ ] **Step 5: Call the new method from `LoanTopupService::executeExpressFlow()`**

Open `app/Tenant/Modules/Loans/Services/LoanTopupService.php`.

Find the end of step 3 in `executeExpressFlow()` (around line 148):

```php
// 3. Generate repayment schedule for the new loan
$this->disbursementService->regenerateSchedule($newLoan, Carbon::instance($newLoan->schedule_date));
```

Add the new call immediately after that line:

```php
// 3. Generate repayment schedule for the new loan
$this->disbursementService->regenerateSchedule($newLoan, Carbon::instance($newLoan->schedule_date));

// 4a. Post disbursement journal entry for the new loan
$this->disbursementService->postTopupDisbursementEntry($newLoan, $staffId);
```

> The existing comment "// 4. Create the topup application record for audit" becomes step 4b — no renumbering required in the code, the comment text is fine.

- [ ] **Step 6: Run the test to confirm it passes**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
php artisan test tests/Feature/Tenant/Loans/LoanTopupAccountingTest.php --filter=test_express_topup_posts_disbursement_journal_entry -v
```

Expected: PASS

- [ ] **Step 7: Run the full test suite to check for regressions**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
php artisan test
```

Expected: All tests pass (or same failures as before this change).

- [ ] **Step 8: Commit**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
git add app/Tenant/Modules/Loans/Contracts/LoanDisbursementServiceInterface.php \
        app/Tenant/Modules/Loans/Services/LoanDisbursementService.php \
        app/Tenant/Modules/Loans/Services/LoanTopupService.php \
        tests/Feature/Tenant/Loans/LoanTopupAccountingTest.php
git commit -m "fix: post disbursement journal entry for express topup new loan"
```

---

## Task 2: Gap 2 — Share Purchase Journal Entry

> **Do NOT start this task until the user has reviewed Task 1.**

**Files:**
- Create: `app/Tenant/Modules/Shares/Services/ShareAccountingService.php`
- Modify: `app/Tenant/Http/Controllers/Api/V1/MemberController.php`
- Create: `tests/Feature/Tenant/Shares/SharePurchaseAccountingTest.php`

### What the bug is

`MemberController::store()` lines 320–332 call `Share::create([...])` when shares are compulsory at onboarding. No journal entry is ever posted. The share capital account (GL 3110) is never touched.

**Missing entry:**
```
DR  Petty Cash (GL 1111)                = share total_value
CR  Ordinary Share Capital (GL 3110)   = share total_value
```

### Architecture: new `ShareAccountingService`

Per CLAUDE.md's enforced layering, business logic lives in the Service layer. A new `ShareAccountingService` is the correct home for this JE. The `MemberController` injects it and calls it after `Share::create()`.

---

- [ ] **Step 1: Write the failing test**

Create directory and file `tests/Feature/Tenant/Shares/SharePurchaseAccountingTest.php`:

```php
<?php

namespace Tests\Feature\Tenant\Shares;

use App\Domain\Tenancy\Entities\Tenant;
use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Shares\Models\Share;
use App\Tenant\Modules\Shares\Services\ShareAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SharePurchaseAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Staff $staff;
    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.central_domain', 'admin.mfukopro.test');
        $testDb = config('database.connections.mysql.database');
        Config::set('database.connections.master.database', $testDb);
        Config::set('database.connections.tenant.database', $testDb);

        DB::purge('master');
        DB::purge('tenant');

        $this->artisan('migrate', [
            '--path' => 'database/migrations/tenant',
            '--database' => 'mysql',
        ]);

        $this->tenant = Tenant::create([
            'id' => 'test-tenant',
            'name' => 'Test Sacco',
            'subdomain' => 'test',
            'database_name' => $testDb,
            'status' => 'active',
        ]);

        $this->staff = Staff::factory()->create();

        // No MemberFactory exists — create directly
        $this->member = \App\Models\Member::create([
            'name' => 'Test Member',
            'member_number' => 'MBR-SHR-001',
            'status' => 'active',
            'phone' => '0700000001',
        ]);

        // Seed the two GL accounts the service depends on
        ChartOfAccount::create([
            'gl_code' => '1111', 'name' => 'Petty Cash', 'account_type' => 'ASSET',
            'account_subtype' => 'Cash', 'normal_balance' => 'DR',
            'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        ChartOfAccount::create([
            'gl_code' => '3110', 'name' => 'Ordinary Share Capital', 'account_type' => 'EQUITY',
            'account_subtype' => 'Share Capital', 'normal_balance' => 'CR',
            'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
    }

    public function test_share_purchase_posts_balanced_journal_entry(): void
    {
        // Arrange
        $share = Share::create([
            'member_id' => $this->member->id,
            'share_no' => 10,
            'share_value' => 500.00,
            'total_value' => 5000.00,
            'purchased_at' => now()->toDateString(),
        ]);

        $service = app(ShareAccountingService::class);

        // Act
        $service->postSharePurchaseEntry($share, $this->staff->id);

        // Assert: one JE was posted
        $je = JournalEntry::on('tenant')
            ->where('reference_type', 'share')
            ->where('reference', "SHR-{$share->id}")
            ->first();

        $this->assertNotNull($je, 'Expected a journal entry for share purchase');
        $this->assertEquals('posted', $je->status);

        $lines = $je->lines()->get();
        $this->assertCount(2, $lines);

        $cashGl = ChartOfAccount::on('tenant')->where('gl_code', '1111')->first();
        $shareCapitalGl = ChartOfAccount::on('tenant')->where('gl_code', '3110')->first();

        $drLine = $lines->firstWhere('account_id', $cashGl->id);
        $crLine = $lines->firstWhere('account_id', $shareCapitalGl->id);

        $this->assertNotNull($drLine, 'Expected DR line on Cash (1111)');
        $this->assertNotNull($crLine, 'Expected CR line on Share Capital (3110)');
        $this->assertEquals(5000.00, (float) $drLine->debit);
        $this->assertEquals(5000.00, (float) $crLine->credit);

        // DR total = CR total (trial balance check)
        $totalDr = $lines->sum('debit');
        $totalCr = $lines->sum('credit');
        $this->assertEquals($totalDr, $totalCr);
    }

    public function test_share_purchase_skips_entry_when_gl_not_configured(): void
    {
        // Remove the GL accounts to simulate unconfigured chart of accounts
        ChartOfAccount::on('tenant')->where('gl_code', '1111')->delete();
        ChartOfAccount::on('tenant')->where('gl_code', '3110')->delete();

        $share = Share::create([
            'member_id' => $this->member->id,
            'share_no' => 5,
            'share_value' => 500.00,
            'total_value' => 2500.00,
            'purchased_at' => now()->toDateString(),
        ]);

        $service = app(ShareAccountingService::class);

        // Should not throw — silently skips
        $service->postSharePurchaseEntry($share, $this->staff->id);

        $this->assertEquals(0, JournalEntry::on('tenant')->count());
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
php artisan test tests/Feature/Tenant/Shares/SharePurchaseAccountingTest.php -v
```

Expected: FAIL — class `ShareAccountingService` not found.

- [ ] **Step 3: Create `ShareAccountingService`**

Create `app/Tenant/Modules/Shares/Services/ShareAccountingService.php`:

```php
<?php

namespace App\Tenant\Modules\Shares\Services;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Models\SubLedger;
use App\Tenant\Modules\Shares\Models\Share;
use Carbon\Carbon;

class ShareAccountingService
{
    /**
     * Post the double-entry journal entry for a share purchase.
     *
     *   DR  Petty Cash (1111)                = total_value
     *   CR  Ordinary Share Capital (3110)    = total_value
     *
     * Silently skips if either GL account is not seeded in the chart of accounts.
     */
    public function postSharePurchaseEntry(Share $share, ?int $actorId): void
    {
        $cashGl = ChartOfAccount::on('tenant')->where('gl_code', '1111')->first();
        $shareCapitalGl = ChartOfAccount::on('tenant')->where('gl_code', '3110')->first();

        if (! $cashGl || ! $shareCapitalGl) {
            return;
        }

        $amount = (float) $share->total_value;
        $date = Carbon::parse($share->purchased_at ?? now());
        $narration = "Share purchase – member #{$share->member_id}";
        $reference = "SHR-{$share->id}";

        $entryNo = sprintf(
            'JE-SHARE-%s-%05d',
            $date->format('Ymd'),
            JournalEntry::on('tenant')->whereDate('created_at', today())->count() + 1,
        );

        $je = JournalEntry::create([
            'entry_no' => $entryNo,
            'date' => $date,
            'period_date' => $date,
            'fiscal_period' => $date->format('Y-m'),
            'journal_type' => 'share',
            'reference' => $reference,
            'reference_type' => 'share',
            'narration' => $narration,
            'status' => 'posted',
            'is_system' => true,
            'posted_by' => $actorId,
            'posted_at' => now(),
            'branch_id' => null,
        ]);

        $lines = [
            ['accountId' => $cashGl->id, 'debit' => $amount, 'credit' => 0.0],
            ['accountId' => $shareCapitalGl->id, 'debit' => 0.0, 'credit' => $amount],
        ];

        foreach ($lines as $lineNo => $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $je->id,
                'account_id' => $line['accountId'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'narration' => $narration,
                'loan_id' => null,
                'member_id' => $share->member_id,
                'branch_id' => null,
                'line_no' => $lineNo + 1,
            ]);

            $this->postToGeneralLedger($je->id, $line['accountId'], $line['debit'], $line['credit'], $date, $narration);
            $this->postToSubLedger($je->id, $line['accountId'], $share->member_id, Member::class, $line['debit'], $line['credit'], $date, $narration);
        }
    }

    private function postToGeneralLedger(int $jeId, int $accountId, float $debit, float $credit, Carbon $date, string $narration): void
    {
        $account = ChartOfAccount::on('tenant')->find($accountId);
        $normalBalance = $account?->normal_balance ?? 'DR';
        $lastBalance = (float) (GeneralLedger::on('tenant')
            ->where('account_id', $accountId)
            ->orderByDesc('id')
            ->value('balance') ?? 0);

        $balance = $normalBalance === 'DR'
            ? round($lastBalance + $debit - $credit, 2)
            : round($lastBalance + $credit - $debit, 2);

        GeneralLedger::create([
            'account_id' => $accountId,
            'journal_entry_id' => $jeId,
            'date' => $date,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
            'narration' => $narration,
        ]);
    }

    private function postToSubLedger(int $jeId, int $accountId, int $entityId, string $entityType, float $debit, float $credit, Carbon $date, string $narration): void
    {
        $account = ChartOfAccount::on('tenant')->find($accountId);
        $normalBalance = $account?->normal_balance ?? 'DR';
        $lastBalance = (float) (SubLedger::on('tenant')
            ->where('account_id', $accountId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderByDesc('id')
            ->value('balance') ?? 0);

        $balance = $normalBalance === 'DR'
            ? round($lastBalance + $debit - $credit, 2)
            : round($lastBalance + $credit - $debit, 2);

        SubLedger::create([
            'account_id' => $accountId,
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'journal_entry_id' => $jeId,
            'date' => $date,
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
            'narration' => $narration,
        ]);
    }
}
```

- [ ] **Step 4: Run the test to confirm it passes**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
php artisan test tests/Feature/Tenant/Shares/SharePurchaseAccountingTest.php -v
```

Expected: Both tests PASS.

- [ ] **Step 5: Wire `ShareAccountingService` into `MemberController`**

Open `app/Tenant/Http/Controllers/Api/V1/MemberController.php`.

**5a.** Find the constructor (or add one if none exists). Inject `ShareAccountingService`:

```php
use App\Tenant\Modules\Shares\Services\ShareAccountingService;
```

Add to existing constructor or create:
```php
public function __construct(
    // ... existing injections ...
    protected ShareAccountingService $shareAccountingService,
) {}
```

> If the constructor already exists, simply add `protected ShareAccountingService $shareAccountingService` to the parameter list. Laravel auto-resolves it.

**5b.** Find the share creation block (around line 324):

```php
if ($shouldCreateShares) {
    Share::create([
        'member_id' => $member->id,
        'share_no' => (int) $sharesQuantity,
        'share_value' => $sharePrice,
        'total_value' => (int) $sharesQuantity * $sharePrice,
        'purchased_at' => now()->toDateString(),
    ]);
}
```

Replace with:

```php
if ($shouldCreateShares) {
    $share = Share::create([
        'member_id' => $member->id,
        'share_no' => (int) $sharesQuantity,
        'share_value' => $sharePrice,
        'total_value' => (int) $sharesQuantity * $sharePrice,
        'purchased_at' => now()->toDateString(),
    ]);
    $this->shareAccountingService->postSharePurchaseEntry($share, auth('tenant')->id());
}
```

- [ ] **Step 6: Run the full test suite**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
php artisan test
```

Expected: All tests pass (or same failures as before this change).

- [ ] **Step 7: Commit**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
git add app/Tenant/Modules/Shares/Services/ShareAccountingService.php \
        app/Tenant/Http/Controllers/Api/V1/MemberController.php \
        tests/Feature/Tenant/Shares/SharePurchaseAccountingTest.php
git commit -m "feat: post DR Cash/CR Share Capital journal entry on share purchase"
```

---

## Gap 3 — No Action Required

The earlier audit incorrectly identified penalty accrual as missing. `LoanPenaltyCalculatorService::postPenaltyAccrual()` (lines 250–295) already posts `DR Penalty Receivable / CR Penalty Income` every time `assessAll()` is called via the `loan:check-arrears` Artisan command (`CheckLoanArrearsCommand`). The JE is correctly incremental — it posts only the **difference** (`$accrualAmount = newPenaltyDue - oldPenaltyDue`) to avoid double-posting. No change needed.
