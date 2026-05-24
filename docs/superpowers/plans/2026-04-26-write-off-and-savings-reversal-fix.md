# Write-Off JE & Savings Reversal GL Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix two accounting bugs: (4) loan write-offs produce no journal entry; (5) reversal fallback path hardcodes GL 2112 instead of resolving mandatory/voluntary/fixed savings GL correctly.

**Architecture:** Issue #4 adds a `LoanWriteOffService` (+ interface + controller action + route) that posts DR 5134 / CR loan_portfolio_account_id. Issue #5 injects `SavingsJournalService` into `TransactionController` and `ReversalService` and routes deposit/withdrawal fallback reversals through it instead of the old `AccountingService`.

**Tech Stack:** Laravel 12, Pest, PostgreSQL (tenant DB), double-entry GL via `LoanAccountingService` / `SavingsJournalService`.

---

## File Map

| Action | Path |
|--------|------|
| Create | `app/Tenant/Modules/Loans/Contracts/LoanWriteOffServiceInterface.php` |
| Create | `app/Tenant/Modules/Loans/Services/LoanWriteOffService.php` |
| Modify | `app/Tenant/Http/Controllers/Api/V1/LoanController.php` |
| Modify | `app/Providers/AppServiceProvider.php` |
| Modify | `routes/tenant_api.php` |
| Create | `tests/Feature/Accounting/LoanWriteOffGlTest.php` |
| Modify | `app/Tenant/Http/Controllers/Api/V1/TransactionController.php` |
| Modify | `app/Tenant/Modules/Transactions/Services/ReversalService.php` |
| Create | `tests/Feature/Accounting/SavingsReversalGlTest.php` |

---

## Task 1: Issue #5 — Fix reversal fallback GL

**Context:** When a deposit or withdrawal is reversed and no original journal entry exists (fallback path), `TransactionController::postReversalAccounting()` and `ReversalService::postReversalAccounting()` call `AccountingService::postWithdrawal()` / `postDeposit()` which hardcode GL 2112. Fix: inject `SavingsJournalService` and route those two cases through `savingsJournal->postWithdrawal($reversalTxn, $savingsAccount)` / `postDeposit($reversalTxn, $savingsAccount)`, which resolve 2111/2112/2113 from `savings_accounts.account_type`.

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/TransactionController.php`
- Modify: `app/Tenant/Modules/Transactions/Services/ReversalService.php`
- Create: `tests/Feature/Accounting/SavingsReversalGlTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Accounting/SavingsReversalGlTest.php`:

```php
<?php

namespace Tests\Feature\Accounting;

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use App\Tenant\Modules\Transactions\Models\Transaction;
use App\Tenant\Modules\Transactions\Services\ReversalService;
use Tests\TenantTestCase;

class SavingsReversalGlTest extends TenantTestCase
{
    private SavingsProduct $product;
    private Member $member;
    private Staff $staff;
    private ChartOfAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed required COA rows
        ChartOfAccount::create([
            'gl_code' => '1112', 'name' => 'Cash at Bank', 'account_type' => 'ASSET',
            'account_subtype' => 'Bank', 'normal_balance' => 'DR', 'level' => 4,
            'is_control' => false, 'is_postable' => true, 'is_active' => true,
        ]);
        ChartOfAccount::create([
            'gl_code' => '2111', 'name' => 'Mandatory Savings', 'account_type' => 'LIABILITY',
            'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 4,
            'is_control' => false, 'is_postable' => true, 'is_active' => true,
        ]);
        ChartOfAccount::create([
            'gl_code' => '2112', 'name' => 'Voluntary Savings', 'account_type' => 'LIABILITY',
            'account_subtype' => 'Member Savings', 'normal_balance' => 'CR', 'level' => 4,
            'is_control' => false, 'is_postable' => true, 'is_active' => true,
        ]);

        $this->cash = ChartOfAccount::where('gl_code', '1112')->first();

        OnboardingSettings::create([
            'reversal_requires_approval' => false,
            'reversal_max_days' => 0,
        ]);

        $this->product = SavingsProduct::create([
            'code' => 'MAN', 'name' => 'Mandatory', 'type' => 'standard', 'status' => 'active',
        ]);

        $this->member = Member::factory()->create();

        $this->staff = Staff::factory()->create();
    }

    public function test_deposit_reversal_fallback_debits_mandatory_gl_2111(): void
    {
        $account = SavingsAccount::create([
            'savings_product_id' => $this->product->id,
            'member_id' => $this->member->id,
            'account_no' => 'MAN-001',
            'account_type' => 'mandatory',
            'balance' => 500,
            'status' => 'active',
            'code' => 'MAN001',
        ]);

        // Deposit transaction with NO original journal entry (forces fallback path)
        $deposit = Transaction::create([
            'reference' => 'DEP-TEST-001',
            'member_id' => $this->member->id,
            'type' => 'deposit',
            'amount' => 500,
            'payment_mode' => 'cash',
            'deposited_by' => 'Test',
            'transaction_date' => now()->toDateString(),
            'account_id' => $account->id,
            'account_type' => SavingsAccount::class,
            'narration' => 'Test deposit',
            'created_by' => $this->staff->id,
        ]);

        $service = app(ReversalService::class);
        $service->requestReversal($deposit, $this->staff, 'Test reversal');

        // The reversal JE should debit 2111 (mandatory savings liability), not 2112
        $reversalRef = Transaction::where('reversal_of', $deposit->id)->value('reference');
        $this->assertNotNull($reversalRef);

        $debitLine = GeneralLedger::where('reference', $reversalRef)
            ->where('debit', '>', 0)
            ->whereHas('account', fn ($q) => $q->where('gl_code', '2111'))
            ->first();

        $this->assertNotNull(
            $debitLine,
            'Expected reversal JE to debit GL 2111 (mandatory savings) but it did not.'
        );
    }

    public function test_deposit_reversal_fallback_does_not_use_hardcoded_gl_2112_for_mandatory_account(): void
    {
        $account = SavingsAccount::create([
            'savings_product_id' => $this->product->id,
            'member_id' => $this->member->id,
            'account_no' => 'MAN-002',
            'account_type' => 'mandatory',
            'balance' => 200,
            'status' => 'active',
            'code' => 'MAN002',
        ]);

        $deposit = Transaction::create([
            'reference' => 'DEP-TEST-002',
            'member_id' => $this->member->id,
            'type' => 'deposit',
            'amount' => 200,
            'payment_mode' => 'cash',
            'deposited_by' => 'Test',
            'transaction_date' => now()->toDateString(),
            'account_id' => $account->id,
            'account_type' => SavingsAccount::class,
            'narration' => 'Test deposit',
            'created_by' => $this->staff->id,
        ]);

        $service = app(ReversalService::class);
        $service->requestReversal($deposit, $this->staff, 'Test reversal');

        $reversalRef = Transaction::where('reversal_of', $deposit->id)->value('reference');

        $wrongGlLine = GeneralLedger::where('reference', $reversalRef)
            ->where('debit', '>', 0)
            ->whereHas('account', fn ($q) => $q->where('gl_code', '2112'))
            ->first();

        $this->assertNull(
            $wrongGlLine,
            'Reversal JE must NOT debit GL 2112 for a mandatory savings account.'
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-backend-2026
php artisan test --filter=SavingsReversalGlTest
```

Expected: 2 failures — the reversal uses GL 2112 instead of 2111.

- [ ] **Step 3: Inject SavingsJournalService and fix TransactionController**

Open `app/Tenant/Http/Controllers/Api/V1/TransactionController.php`.

Add to use imports:
```php
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
```

Change constructor from:
```php
public function __construct(protected AccountingService $accounting) {}
```
to:
```php
public function __construct(
    protected AccountingService $accounting,
    protected SavingsJournalService $savingsJournal,
) {}
```

Change `postReversalAccounting()` fallback match expression (lines ~159–174) from:
```php
match ($txn->type) {
    'deposit' => $this->accounting->postWithdrawal(
        memberId: $memberId, savingsAccountId: $savingsId,
        amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
    ),
    'withdrawal' => $this->accounting->postDeposit(
        memberId: $memberId, savingsAccountId: $savingsId,
        amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
    ),
    'charge' => $this->accounting->postChargeReversal(
        memberId: $memberId, savingsAccountId: $savingsId,
        amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
        debitAccountId: $txn->gl_credit_account_id,
    ),
    default => null,
};
```
to:
```php
$savingsAccount = SavingsAccount::find($txn->account_id);
$reversalTxn = Transaction::where('reference', $reversalRef)->first();

match ($txn->type) {
    'deposit' => ($savingsAccount && $reversalTxn)
        ? $this->savingsJournal->postWithdrawal($reversalTxn, $savingsAccount)
        : $this->accounting->postWithdrawal(
            memberId: $memberId, savingsAccountId: $savingsId,
            amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
        ),
    'withdrawal' => ($savingsAccount && $reversalTxn)
        ? $this->savingsJournal->postDeposit($reversalTxn, $savingsAccount)
        : $this->accounting->postDeposit(
            memberId: $memberId, savingsAccountId: $savingsId,
            amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
        ),
    'charge' => $this->accounting->postChargeReversal(
        memberId: $memberId, savingsAccountId: $savingsId,
        amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
        debitAccountId: $txn->gl_credit_account_id,
    ),
    default => null,
};
```

- [ ] **Step 4: Inject SavingsJournalService and fix ReversalService**

Open `app/Tenant/Modules/Transactions/Services/ReversalService.php`.

Add to use imports:
```php
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
```

Change constructor from:
```php
public function __construct(protected AccountingService $accounting) {}
```
to:
```php
public function __construct(
    protected AccountingService $accounting,
    protected SavingsJournalService $savingsJournal,
) {}
```

Change `postReversalAccounting()` fallback match (lines ~252–267) from:
```php
match ($txn->type) {
    'deposit' => $this->accounting->postWithdrawal(
        memberId: $memberId, savingsAccountId: $savingsId,
        amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
    ),
    'withdrawal' => $this->accounting->postDeposit(
        memberId: $memberId, savingsAccountId: $savingsId,
        amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
    ),
    'charge' => $this->accounting->postChargeReversal(
        memberId: $memberId, savingsAccountId: $savingsId,
        amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
        debitAccountId: $txn->gl_credit_account_id,
    ),
    default => null,
};
```
to:
```php
$savingsAccount = SavingsAccount::find($txn->account_id);
$reversalTxn = Transaction::where('reference', $reversalRef)->first();

match ($txn->type) {
    'deposit' => ($savingsAccount && $reversalTxn)
        ? $this->savingsJournal->postWithdrawal($reversalTxn, $savingsAccount)
        : $this->accounting->postWithdrawal(
            memberId: $memberId, savingsAccountId: $savingsId,
            amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
        ),
    'withdrawal' => ($savingsAccount && $reversalTxn)
        ? $this->savingsJournal->postDeposit($reversalTxn, $savingsAccount)
        : $this->accounting->postDeposit(
            memberId: $memberId, savingsAccountId: $savingsId,
            amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
        ),
    'charge' => $this->accounting->postChargeReversal(
        memberId: $memberId, savingsAccountId: $savingsId,
        amount: $amount, reference: $reversalRef, date: $date, narration: $narration,
        debitAccountId: $txn->gl_credit_account_id,
    ),
    default => null,
};
```

- [ ] **Step 5: Run tests and verify they pass**

```bash
php artisan test --filter=SavingsReversalGlTest
```

Expected: 2 passing.

- [ ] **Step 6: Run full test suite to check for regressions**

```bash
composer test
```

Expected: all existing tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/TransactionController.php \
        app/Tenant/Modules/Transactions/Services/ReversalService.php \
        tests/Feature/Accounting/SavingsReversalGlTest.php
git commit -m "fix: route savings reversal fallback through SavingsJournalService for correct GL"
```

---

## Task 2: Issue #4 — Loan write-off journal entry

**Context:** `LoanStatus::WrittenOff` exists but no service, controller action, or route posts a JE when a loan is written off. Create `LoanWriteOffService` (+ interface) that posts DR 5134 (Loan Write-off Expense) / CR `loan_product.loan_portfolio_account_id` for the `outstanding_balance`, transitions the loan status to `written_off`, and binds the interface in `AppServiceProvider`. Add `POST /loans/{loan}/write-off` route and `LoanController::writeOff()` action.

**Files:**
- Create: `app/Tenant/Modules/Loans/Contracts/LoanWriteOffServiceInterface.php`
- Create: `app/Tenant/Modules/Loans/Services/LoanWriteOffService.php`
- Modify: `app/Tenant/Http/Controllers/Api/V1/LoanController.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/tenant_api.php`
- Create: `tests/Feature/Accounting/LoanWriteOffGlTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Accounting/LoanWriteOffGlTest.php`:

```php
<?php

namespace Tests\Feature\Accounting;

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Services\LoanWriteOffService;
use Tests\TenantTestCase;

class LoanWriteOffGlTest extends TenantTestCase
{
    private ChartOfAccount $portfolioAccount;
    private ChartOfAccount $writeOffAccount;
    private LoanProduct $loanProduct;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->portfolioAccount = ChartOfAccount::create([
            'gl_code' => '1131', 'name' => 'Personal Loans', 'account_type' => 'ASSET',
            'account_subtype' => 'Loan', 'normal_balance' => 'DR', 'level' => 4,
            'is_control' => false, 'is_postable' => true, 'is_active' => true,
        ]);

        $this->writeOffAccount = ChartOfAccount::create([
            'gl_code' => '5134', 'name' => 'Loan Write-off Expense', 'account_type' => 'EXPENSE',
            'account_subtype' => 'Provision Expense', 'normal_balance' => 'DR', 'level' => 4,
            'is_control' => false, 'is_postable' => true, 'is_active' => true,
        ]);

        $this->loanProduct = LoanProduct::factory()->create([
            'loan_portfolio_account_id' => $this->portfolioAccount->id,
        ]);

        $this->member = Member::factory()->create();
    }

    public function test_write_off_posts_debit_to_gl_5134(): void
    {
        $loan = Loan::factory()->create([
            'member_id' => $this->member->id,
            'loan_product_id' => $this->loanProduct->id,
            'status' => LoanStatus::Arrears,
            'outstanding_balance' => 1500.00,
        ]);

        $service = app(LoanWriteOffService::class);
        $service->writeOff($loan, actorId: 1, narration: 'Unrecoverable');

        $debitLine = JournalEntryLine::whereHas('journalEntry', fn ($q) => $q->where('loan_id', $loan->id))
            ->where('account_id', $this->writeOffAccount->id)
            ->where('debit', 1500.00)
            ->where('credit', 0)
            ->first();

        $this->assertNotNull($debitLine, 'Expected DR 5134 for 1500.00 in write-off JE.');
    }

    public function test_write_off_posts_credit_to_loan_portfolio_account(): void
    {
        $loan = Loan::factory()->create([
            'member_id' => $this->member->id,
            'loan_product_id' => $this->loanProduct->id,
            'status' => LoanStatus::Arrears,
            'outstanding_balance' => 1500.00,
        ]);

        $service = app(LoanWriteOffService::class);
        $service->writeOff($loan, actorId: 1, narration: 'Unrecoverable');

        $creditLine = JournalEntryLine::whereHas('journalEntry', fn ($q) => $q->where('loan_id', $loan->id))
            ->where('account_id', $this->portfolioAccount->id)
            ->where('credit', 1500.00)
            ->where('debit', 0)
            ->first();

        $this->assertNotNull($creditLine, 'Expected CR loan portfolio account for 1500.00 in write-off JE.');
    }

    public function test_write_off_transitions_loan_status_to_written_off(): void
    {
        $loan = Loan::factory()->create([
            'member_id' => $this->member->id,
            'loan_product_id' => $this->loanProduct->id,
            'status' => LoanStatus::Arrears,
            'outstanding_balance' => 1500.00,
        ]);

        $service = app(LoanWriteOffService::class);
        $service->writeOff($loan, actorId: 1, narration: 'Unrecoverable');

        $this->assertSame(LoanStatus::WrittenOff->value, $loan->fresh()->status->value);
    }

    public function test_write_off_rejects_closed_loan(): void
    {
        $loan = Loan::factory()->create([
            'member_id' => $this->member->id,
            'loan_product_id' => $this->loanProduct->id,
            'status' => LoanStatus::Closed,
            'outstanding_balance' => 1500.00,
        ]);

        $service = app(LoanWriteOffService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->writeOff($loan, actorId: 1, narration: 'Should fail');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=LoanWriteOffGlTest
```

Expected: tests fail because `LoanWriteOffService` does not exist.

- [ ] **Step 3: Create the interface**

Create `app/Tenant/Modules/Loans/Contracts/LoanWriteOffServiceInterface.php`:

```php
<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\Loan;

interface LoanWriteOffServiceInterface
{
    public function writeOff(Loan $loan, int $actorId, string $narration = ''): void;
}
```

- [ ] **Step 4: Create LoanWriteOffService**

Create `app/Tenant/Modules/Loans/Services/LoanWriteOffService.php`:

```php
<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\LoanAccountingService;
use App\Tenant\Modules\Loans\Contracts\LoanWriteOffServiceInterface;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanWriteOffService implements LoanWriteOffServiceInterface
{
    public function __construct(
        private readonly LoanStatusGuard $guard,
        private readonly LoanAccountingService $accounting,
    ) {}

    public function writeOff(Loan $loan, int $actorId, string $narration = ''): void
    {
        $allowedStatuses = [LoanStatus::Arrears, LoanStatus::Disbursed, LoanStatus::Active];

        if (! in_array($loan->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'loan' => ['Only loans in arrears, disbursed, or active status can be written off.'],
            ]);
        }

        $outstanding = (float) $loan->outstanding_balance;

        if ($outstanding <= 0) {
            throw ValidationException::withMessages([
                'loan' => ['Cannot write off a loan with zero outstanding balance.'],
            ]);
        }

        $loan->loadMissing('loanProduct');
        $portfolioAccountId = $loan->loanProduct?->loan_portfolio_account_id;

        if (! $portfolioAccountId) {
            throw ValidationException::withMessages([
                'loan' => ['Loan product has no loan portfolio GL account configured.'],
            ]);
        }

        $writeOffAccountId = ChartOfAccount::where('gl_code', '5134')
            ->where('is_active', true)
            ->value('id');

        if (! $writeOffAccountId) {
            throw ValidationException::withMessages([
                'loan' => ['Write-off expense account (GL 5134) is not configured in chart of accounts.'],
            ]);
        }

        DB::connection('tenant')->transaction(function () use ($loan, $outstanding, $portfolioAccountId, $writeOffAccountId, $actorId, $narration) {
            $this->accounting->postJournalEntry(
                loan: $loan,
                typeCode: 'LOAN_WRITE_OFF',
                narration: $narration ?: "Loan write-off — {$loan->loan_number}",
                lines: [
                    $this->accounting->line($writeOffAccountId, $outstanding, 0.0, 'Write-off expense', $loan->id),
                    $this->accounting->line($portfolioAccountId, 0.0, $outstanding, 'Loan portfolio reduction', $loan->id),
                ],
                date: Carbon::today(),
                actorId: $actorId,
            );

            $this->guard->transition($loan, LoanStatus::WrittenOff, $narration ?: 'Written off');
        });
    }
}
```

- [ ] **Step 5: Bind interface in AppServiceProvider**

Open `app/Providers/AppServiceProvider.php`. Add to the use imports at the top:

```php
use App\Tenant\Modules\Loans\Contracts\LoanWriteOffServiceInterface;
use App\Tenant\Modules\Loans\Services\LoanWriteOffService;
```

Add a new `$this->app->bind()` call inside `register()` with the other loan bindings:

```php
$this->app->bind(
    LoanWriteOffServiceInterface::class,
    LoanWriteOffService::class,
);
```

- [ ] **Step 6: Run tests again — they should now pass**

```bash
php artisan test --filter=LoanWriteOffGlTest
```

Expected: 4 passing.

- [ ] **Step 7: Add writeOff action to LoanController**

Open `app/Tenant/Http/Controllers/Api/V1/LoanController.php`.

Add to use imports:
```php
use App\Tenant\Modules\Loans\Contracts\LoanWriteOffServiceInterface;
use Illuminate\Http\Request;
```

Inject in constructor (alongside existing `$activityService`):
```php
public function __construct(
    private readonly LoanActivityServiceInterface $activityService,
    private readonly LoanWriteOffServiceInterface $writeOffService,
) {}
```

Add the `writeOff` method at the end of the class (before the closing `}`):

```php
/**
 * POST /loans/{loan}/write-off
 * Mark a loan as written off and post the write-off journal entry.
 */
public function writeOff(Request $request, Loan $loan): JsonResponse
{
    $data = $request->validate([
        'narration' => 'nullable|string|max:500',
    ]);

    $this->writeOffService->writeOff(
        loan: $loan,
        actorId: (int) auth()->id(),
        narration: $data['narration'] ?? '',
    );

    return response()->json([
        'message' => 'Loan written off successfully.',
        'loan_number' => $loan->loan_number,
        'status' => LoanStatus::WrittenOff->value,
    ]);
}
```

- [ ] **Step 8: Add route**

Open `routes/tenant_api.php`. Find the loans routes block. Add after the reschedule route group:

```php
Route::post('loans/{loan}/write-off', [LoanController::class, 'writeOff']);
```

- [ ] **Step 9: Run full test suite**

```bash
composer test
```

Expected: all tests pass.

- [ ] **Step 10: Commit**

```bash
git add \
  app/Tenant/Modules/Loans/Contracts/LoanWriteOffServiceInterface.php \
  app/Tenant/Modules/Loans/Services/LoanWriteOffService.php \
  app/Tenant/Http/Controllers/Api/V1/LoanController.php \
  app/Providers/AppServiceProvider.php \
  routes/tenant_api.php \
  tests/Feature/Accounting/LoanWriteOffGlTest.php
git commit -m "feat: add loan write-off service with JE posting DR 5134 / CR loan portfolio"
```
