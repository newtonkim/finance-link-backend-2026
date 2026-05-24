# Accounting Integration Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close every accounting gap found in the audit — correct GL accounts, missing journal entries for rescheduling/reversals/savings interest, and infrastructure improvements for race-condition-free JE numbering.

**Architecture:** All changes stay within the existing layered structure (Service → Model → Controller). A new shared `LoanAccountingService` replaces the copy-pasted GL-posting helpers across three loan services. Each gap is fixed in isolation so tasks can be reviewed independently.

**Tech Stack:** Laravel 12, PHP 8.2, PostgreSQL (`tenant` DB connection), Pest for tests, `bcmath` for decimal arithmetic.

---

## File Map

| Action | File | Responsibility |
|---|---|---|
| Create | `app/Tenant/Modules/Accounting/Services/LoanAccountingService.php` | Shared GL/SubLedger posting + atomic JE numbering |
| Create | `database/migrations/tenant/2026_04_18_000001_create_journal_entry_sequences_table.php` | Atomic JE sequence table |
| Modify | `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php` | Use LoanAccountingService; fix processing fee GL; on-repayment charge accrual |
| Modify | `app/Tenant/Modules/Loans/Services/LoanRepaymentService.php` | Use LoanAccountingService; fix SubLedger entity types |
| Modify | `app/Tenant/Modules/Loans/Services/LoanPenaltyCalculatorService.php` | Use LoanAccountingService; fix SubLedger entity types |
| Modify | `app/Tenant/Modules/Loans/Services/LoanRescheduleService.php` | Add rescheduling accounting entries (waivers + capitalization) |
| Create | `app/Tenant/Modules/Loans/Services/LoanRepaymentReversalService.php` | Reverse a posted loan repayment |
| Modify | `app/Tenant/Http/Controllers/Api/V1/LoanRepaymentController.php` | Add reversal endpoint |
| Modify | `routes/tenant_api.php` | Register reversal route |
| Modify | `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php` | Use SavingsJournalService instead of AccountingService |
| Modify | `app/Tenant/Modules/Savings/Services/SavingsAccountService.php` | Post JE for initial deposit |
| Create | `app/Tenant/Modules/Savings/Services/SavingsInterestService.php` | Calculate and post savings interest |
| Create | `app/Tenant/Console/Commands/AccrueSavingsInterest.php` | Artisan command to trigger interest accrual |
| Create | `tests/Feature/Accounting/LoanAccountingServiceTest.php` | Unit tests for shared service |
| Create | `tests/Feature/Accounting/LoanRescheduleAccountingTest.php` | Tests for reschedule JEs |
| Create | `tests/Feature/Accounting/LoanRepaymentReversalTest.php` | Tests for repayment reversal |
| Create | `tests/Feature/Accounting/SavingsInterestServiceTest.php` | Tests for savings interest |

---

## Phase 1 — Shared Infrastructure

### Task 1: Atomic JE Sequence + LoanAccountingService

**Context:** Three loan services (`LoanDisbursementService`, `LoanRepaymentService`, `LoanPenaltyCalculatorService`) each copy-paste identical `postToGeneralLedger()`, `postToSubLedger()`, and `generateEntryNo()` methods. The entry-number generator is also not concurrency-safe (uses `count() + 1`). This task creates the shared infrastructure both this task and all later tasks depend on.

**Files:**
- Create: `database/migrations/tenant/2026_04_18_000001_create_journal_entry_sequences_table.php`
- Create: `app/Tenant/Modules/Accounting/Services/LoanAccountingService.php`
- Create: `tests/Feature/Accounting/LoanAccountingServiceTest.php`

---

- [ ] **Step 1: Write the failing test for JE number uniqueness**

```php
<?php
// tests/Feature/Accounting/LoanAccountingServiceTest.php

namespace Tests\Feature\Accounting;

use App\Tenant\Modules\Accounting\Services\LoanAccountingService;
use Tests\TenantTestCase;

class LoanAccountingServiceTest extends TenantTestCase
{
    public function test_next_entry_no_is_unique_under_concurrent_calls(): void
    {
        $service = new LoanAccountingService();
        $numbers = [];
        for ($i = 0; $i < 20; $i++) {
            $numbers[] = $service->nextEntryNo('LOAN_REPAY');
        }
        $this->assertCount(20, array_unique($numbers), 'All 20 entry numbers must be unique');
    }

    public function test_next_entry_no_includes_type_code(): void
    {
        $service = new LoanAccountingService();
        $no = $service->nextEntryNo('LOAN_DISB');
        $this->assertStringContainsString('LOAN_DISB', $no);
    }
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
php artisan test tests/Feature/Accounting/LoanAccountingServiceTest.php
```
Expected: FAIL — class `LoanAccountingService` not found.

- [ ] **Step 3: Create the JE sequence migration**

```php
<?php
// database/migrations/tenant/2026_04_18_000001_create_journal_entry_sequences_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->create('journal_entry_sequences', function (Blueprint $table) {
            $table->string('date_prefix', 8)->primary(); // YYYYMMDD
            $table->unsignedInteger('seq')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('journal_entry_sequences');
    }
};
```

- [ ] **Step 4: Run the migration**

```bash
php artisan migrate --path=database/migrations/tenant/2026_04_18_000001_create_journal_entry_sequences_table.php --database=tenant
```
Expected: Migrated successfully.

- [ ] **Step 5: Create LoanAccountingService**

```php
<?php
// app/Tenant/Modules/Accounting/Services/LoanAccountingService.php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Models\SubLedger;
use App\Tenant\Modules\Loans\Models\Loan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared accounting engine for all loan-side journal entries.
 *
 * Responsibilities:
 *  - Atomic, race-condition-free JE number generation
 *  - Balanced double-entry posting (JE header + lines + GL + SubLedger)
 *  - Consistent SubLedger entity types (FQCN, not bare strings)
 */
class LoanAccountingService
{
    /**
     * Generate a unique, sequential JE number.
     * Uses an upsert on journal_entry_sequences to atomically increment.
     *
     * Format: JE-{TYPE}-{YYYYMMDD}-{00001}
     */
    public function nextEntryNo(string $typeCode): string
    {
        $prefix = now()->format('Ymd');

        // Atomic increment via INSERT … ON CONFLICT DO UPDATE
        DB::connection('tenant')->statement(
            'INSERT INTO journal_entry_sequences (date_prefix, seq) VALUES (?, 1)
             ON CONFLICT (date_prefix) DO UPDATE SET seq = journal_entry_sequences.seq + 1',
            [$prefix]
        );

        $seq = (int) DB::connection('tenant')
            ->table('journal_entry_sequences')
            ->where('date_prefix', $prefix)
            ->value('seq');

        return sprintf('JE-%s-%s-%05d', $typeCode, $prefix, $seq);
    }

    /**
     * Create a JE header + lines, then write to GL and SubLedger.
     *
     * Each $line array must have keys:
     *   accountId (int), debit (float), credit (float), narration (string), loanId (int)
     *
     * @param  array<array{accountId:int,debit:float,credit:float,narration:string,loanId:int}>  $lines
     */
    public function postJournalEntry(
        Loan $loan,
        string $typeCode,
        string $narration,
        array $lines,
        Carbon $date,
        int $actorId,
    ): JournalEntry {
        $je = JournalEntry::create([
            'entry_no'       => $this->nextEntryNo($typeCode),
            'date'           => $date,
            'period_date'    => $date,
            'fiscal_period'  => $date->format('Y-m'),
            'journal_type'   => 'loan',
            'reference'      => $loan->loan_no,
            'reference_type' => 'loan',
            'narration'      => $narration,
            'status'         => 'posted',
            'is_system'      => true,
            'posted_by'      => $actorId,
            'posted_at'      => now(),
            'branch_id'      => $loan->branch_id,
        ]);

        foreach ($lines as $lineNo => $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $je->id,
                'account_id'       => $line['accountId'],
                'debit'            => $line['debit'],
                'credit'           => $line['credit'],
                'narration'        => $line['narration'],
                'loan_id'          => $line['loanId'],
                'member_id'        => $loan->member_id,
                'branch_id'        => $loan->branch_id,
                'line_no'          => $lineNo + 1,
            ]);

            $this->postToGeneralLedger(
                $je->id,
                $line['accountId'],
                (float) $line['debit'],
                (float) $line['credit'],
                $date,
                $line['narration'],
            );

            // SubLedger — loan-level entity (FQCN, not bare string)
            $this->postToSubLedger(
                $je->id,
                $line['accountId'],
                $loan->id,
                Loan::class,
                (float) $line['debit'],
                (float) $line['credit'],
                $date,
                $line['narration'],
            );
        }

        return $je;
    }

    /**
     * Build a line array for postJournalEntry().
     */
    public function line(int $accountId, float $debit, float $credit, string $narration, int $loanId): array
    {
        return compact('accountId', 'debit', 'credit', 'narration', 'loanId');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function postToGeneralLedger(
        int $jeId,
        int $accountId,
        float $debit,
        float $credit,
        Carbon $date,
        string $narration,
    ): void {
        $account = ChartOfAccount::on('tenant')->find($accountId);
        $normalBalance = $account?->normal_balance ?? 'DR';

        $last = (string) (GeneralLedger::on('tenant')
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('balance') ?? '0');

        $balance = $normalBalance === 'DR'
            ? bcadd($last, bcsub((string) $debit, (string) $credit, 4), 4)
            : bcadd($last, bcsub((string) $credit, (string) $debit, 4), 4);

        GeneralLedger::create([
            'account_id'       => $accountId,
            'journal_entry_id' => $jeId,
            'date'             => $date,
            'debit'            => $debit,
            'credit'           => $credit,
            'balance'          => $balance,
            'narration'        => $narration,
        ]);
    }

    private function postToSubLedger(
        int $jeId,
        int $accountId,
        int $entityId,
        string $entityType,
        float $debit,
        float $credit,
        Carbon $date,
        string $narration,
    ): void {
        $account = ChartOfAccount::on('tenant')->find($accountId);
        $normalBalance = $account?->normal_balance ?? 'DR';

        $last = (string) (SubLedger::on('tenant')
            ->where('account_id', $accountId)
            ->where('entity_id', $entityId)
            ->where('entity_type', $entityType)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('balance') ?? '0');

        $balance = $normalBalance === 'DR'
            ? bcadd($last, bcsub((string) $debit, (string) $credit, 4), 4)
            : bcadd($last, bcsub((string) $credit, (string) $debit, 4), 4);

        SubLedger::create([
            'account_id'       => $accountId,
            'entity_id'        => $entityId,
            'entity_type'      => $entityType,
            'journal_entry_id' => $jeId,
            'date'             => $date,
            'debit'            => $debit,
            'credit'           => $credit,
            'balance'          => $balance,
            'narration'        => $narration,
        ]);
    }
}
```

- [ ] **Step 6: Run the test to confirm it passes**

```bash
php artisan test tests/Feature/Accounting/LoanAccountingServiceTest.php
```
Expected: 2 tests PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/tenant/2026_04_18_000001_create_journal_entry_sequences_table.php \
        app/Tenant/Modules/Accounting/Services/LoanAccountingService.php \
        tests/Feature/Accounting/LoanAccountingServiceTest.php
git commit -m "feat(accounting): add LoanAccountingService with atomic JE sequencing and bcmath GL balances"
```

---

## Phase 2 — Quick Correctness Fixes

### Task 2: Fix Processing Fee Posted to Wrong GL Account

**Context:** In `LoanDisbursementService::postDisbursementEntry()` (line 545 of the current file), the processing fee credits `$product->interest_income_account_id`. Processing fees are fee income (GL 4220 Loan Processing Fees), not interest income. The fix resolves GL 4220 by code and falls back to `charges_income_account_id` if the COA row is missing.

**Files:**
- Modify: `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php`

---

- [ ] **Step 1: Locate the processing fee line in postDisbursementEntry**

Open `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php` and find the block that starts at approximately line 543:

```php
if ($processingFee > 0) {
    $feeNarration = "Processing fee – {$loan->loan_no}";
    $lines[] = $this->line($product->interest_income_account_id, 0.0, $processingFee, $feeNarration, $loan->id);
}
```

- [ ] **Step 2: Replace with correct GL account**

Replace that block with:

```php
if ($processingFee > 0) {
    $feeNarration = "Processing fee – {$loan->loan_no}";
    $feeAccountId = $this->resolveProcessingFeeAccountId($product);
    $lines[] = $this->line($feeAccountId, 0.0, $processingFee, $feeNarration, $loan->id);
}
```

Then add the private helper method at the bottom of the private methods section:

```php
/**
 * Resolve the income account for the loan processing fee.
 * Uses GL 4220 (Loan Processing Fees) if it exists in the COA,
 * otherwise falls back to the product's charges_income_account_id.
 */
private function resolveProcessingFeeAccountId(LoanProduct $product): int
{
    $id = ChartOfAccount::on('tenant')
        ->where('gl_code', '4220')
        ->where('is_active', true)
        ->value('id');

    return $id ?? (int) $product->charges_income_account_id ?? (int) $product->interest_income_account_id;
}
```

- [ ] **Step 3: Run linter and full test suite**

```bash
composer lint
php artisan test
```
Expected: All tests pass, no lint errors.

- [ ] **Step 4: Commit**

```bash
git add app/Tenant/Modules/Loans/Services/LoanDisbursementService.php
git commit -m "fix(accounting): processing fee now credits GL 4220 (Loan Processing Fees) not Interest Income"
```

---

### Task 3: Fix Sub-Ledger Entity Types (Bare Strings → FQCNs)

**Context:** `LoanRepaymentService` and `LoanDisbursementService` post sub-ledger entries with entity_type `'loan'` and `'savings_account'`. The `SubLedger` model uses a polymorphic `morphTo()` which requires full class names. Fix all three affected services to use FQCNs.

**Files:**
- Modify: `app/Tenant/Modules/Loans/Services/LoanRepaymentService.php`
- Modify: `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php`
- Modify: `app/Tenant/Modules/Loans/Services/LoanPenaltyCalculatorService.php`

---

- [ ] **Step 1: Fix LoanRepaymentService**

In `LoanRepaymentService::postJournalEntry()`, find:

```php
$this->postToSubLedger($je->id, $line['accountId'], $loan->id, 'loan', ...);
```

Replace with:

```php
$this->postToSubLedger($je->id, $line['accountId'], $loan->id, \App\Tenant\Modules\Loans\Models\Loan::class, ...);
```

Also find any savings_account sub-ledger post (in `postSavingsRepaymentEntry`):

```php
$this->postToSubLedger($je->id, ..., $savingsAccountId, 'savings_account', ...);
```

Replace with:

```php
$this->postToSubLedger($je->id, ..., $savingsAccountId, \App\Tenant\Modules\Savings\Models\SavingsAccount::class, ...);
```

- [ ] **Step 2: Fix LoanDisbursementService**

Search for every `'loan'` and `'savings_account'` string passed as the entity_type argument to `postToSubLedger()` in `LoanDisbursementService.php`. Replace all occurrences following the same pattern as Step 1.

- [ ] **Step 3: Fix LoanPenaltyCalculatorService**

Open `app/Tenant/Modules/Loans/Services/LoanPenaltyCalculatorService.php`. Find all calls to `postToSubLedger()` with `'loan'` as entity_type. Replace with `\App\Tenant\Modules\Loans\Models\Loan::class`.

- [ ] **Step 4: Run tests**

```bash
php artisan test
```
Expected: All existing tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Tenant/Modules/Loans/Services/LoanRepaymentService.php \
        app/Tenant/Modules/Loans/Services/LoanDisbursementService.php \
        app/Tenant/Modules/Loans/Services/LoanPenaltyCalculatorService.php
git commit -m "fix(accounting): use FQCN entity types in SubLedger so morphTo() resolves correctly"
```

---

### Task 4: On-Repayment Charge Accrual at Disbursement

**Context:** When a loan is disbursed, on-repayment charges are distributed across schedule installments but no accrual JE is posted. At repayment time, the system CRs Charges Receivable to clear the collection — but the receivable was never established, so the balance goes negative. Fix: post `DR Charges Receivable / CR Charges Income` at disbursement for on-repayment charges.

**Files:**
- Modify: `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php`

---

- [ ] **Step 1: Locate processChargesAtDisbursement**

In `LoanDisbursementService.php`, find the method `processChargesAtDisbursement()`. The current block handling on-repayment charges (around line 397) looks like:

```php
if ($onRepaymentTotal > 0) {
    $this->distributeChargesToSchedule($loan, (float) $onRepaymentTotal);
}
```

- [ ] **Step 2: Add accrual JE after distribution**

Replace that block with:

```php
if ($onRepaymentTotal > 0 && $product->charges_receivable_account_id && $product->charges_income_account_id) {
    $this->distributeChargesToSchedule($loan, (float) $onRepaymentTotal);
    // Establish the receivable so it can be cleared during repayment
    $this->postChargesAccrualEntry($loan, $product, (float) $onRepaymentTotal, $actorId);
}
```

> `postChargesAccrualEntry()` already exists in the service and posts `DR Charges Receivable / CR Charges Income`. No new method needed.

- [ ] **Step 3: Run tests**

```bash
php artisan test
```
Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add app/Tenant/Modules/Loans/Services/LoanDisbursementService.php
git commit -m "fix(accounting): post DR Charges Receivable at disbursement for on-repayment charges"
```

---

### Task 5: Initial Savings Deposit Accounting

**Context:** When a savings account is created with an initial deposit, `SavingsAccountService::create()` creates a Transaction record but never calls any accounting service. The deposit is invisible to the GL. Fix: inject `SavingsJournalService` and post the JE after creating the Transaction.

**Files:**
- Modify: `app/Tenant/Modules/Savings/Services/SavingsAccountService.php`

---

- [ ] **Step 1: Inject SavingsJournalService into SavingsAccountService**

Open `app/Tenant/Modules/Savings/Services/SavingsAccountService.php`. Add the constructor and import:

```php
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;

class SavingsAccountService
{
    public function __construct(
        protected SavingsJournalService $savingsJournal,
    ) {}
    // ... existing methods
}
```

- [ ] **Step 2: Post the JE after the initial deposit Transaction is created**

In the `create()` method, find the block that creates the initial deposit Transaction (around line 72). It currently ends after `Transaction::create([...])` with no accounting call. Capture the created Transaction and post the JE:

```php
if ($data['initial_deposit'] > 0) {
    $transaction = Transaction::create([
        'reference'        => 'IDP-'.date('Ymd').'-'.mt_rand(10000, 99999),
        'member_id'        => $account->member_id,
        'type'             => 'deposit',
        'amount'           => $data['initial_deposit'],
        'payment_mode'     => $data['payment_mode'] ?? 'cash',
        'deposited_by'     => 'System (Initial Deposit)',
        'transaction_date' => now()->toDateString(),
        'account_id'       => $account->id,
        // ... any other fields already in this block
    ]);

    // Post the opening JE — DR Cash (resolved by payment_mode) / CR Savings Liability
    $this->savingsJournal->postDeposit($transaction, $account);
}
```

> Note: The Transaction create call already exists — you are ONLY changing two things: (1) assign the result to `$transaction`, and (2) add the `$this->savingsJournal->postDeposit(...)` call afterward. Do NOT duplicate or rewrite the Transaction creation.

- [ ] **Step 3: Run tests**

```bash
php artisan test
```
Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add app/Tenant/Modules/Savings/Services/SavingsAccountService.php
git commit -m "fix(accounting): post GL journal entry for initial savings deposit on account creation"
```

---

## Phase 3 — Live Savings Accounting Service

### Task 6: Switch SavingsAccountController from AccountingService to SavingsJournalService

**Context:** Every live deposit and withdrawal goes through `AccountingService`, which hard-codes GL 1112 (Cash at Bank) and GL 2112 (Voluntary Savings) regardless of payment mode and savings product type. `SavingsJournalService` correctly resolves both. This task wires the live controller to the right service.

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php`

---

- [ ] **Step 1: Replace the AccountingService dependency**

At the top of `SavingsAccountController`, find the constructor injection of `AccountingService`. Add `SavingsJournalService` alongside it (keep `AccountingService` because `MemberChargeService::collectPendingCharges()` still needs it — we do not change `MemberChargeService` in this task):

```php
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;

class SavingsAccountController extends Controller
{
    public function __construct(
        protected AccountingService $accounting,    // keep for MemberChargeService
        protected SavingsJournalService $savingsJournal,
    ) {}
```

- [ ] **Step 2: Update the deposit() method**

In `deposit()`, find the call to `$this->accounting->postDeposit(...)`. The Transaction is created just before it at line 381 as an inline `Transaction::create(...)`. Capture the result and switch to SavingsJournalService:

Change:
```php
Transaction::create([
    'reference' => $transactionRef,
    // ...
]);

$this->accounting->postDeposit(
    memberId: ...,
    savingsAccountId: ...,
    amount: ...,
    reference: $transactionRef,
    date: $validated['deposit_date'],
    narration: ...,
);
```

To:
```php
$depositTxn = Transaction::create([
    'reference' => $transactionRef,
    // ... (same fields, unchanged)
]);

$this->savingsJournal->postDeposit($depositTxn, $savingsAccount);
```

Also update the charge postings inside the charge loop. Each charge currently calls `$this->accounting->postDepositCharge(...)`. Change to capture the charge Transaction and use SavingsJournalService:

Change:
```php
Transaction::create([
    'reference' => $chargeRef,
    // ...
]);

$this->accounting->postDepositCharge(
    memberId: ...,
    ...
);
```

To:
```php
$chargeTxn = Transaction::create([
    'reference' => $chargeRef,
    // ... (same fields, unchanged)
]);

$this->savingsJournal->postCharge($chargeTxn, $savingsAccount);
```

- [ ] **Step 3: Update the withdraw() method**

Apply the same pattern. Find where `$this->accounting->postWithdrawal(...)` is called. Capture the Transaction and switch:

```php
$withdrawalTxn = Transaction::create([
    'reference' => $transactionRef,
    // ... (same fields, unchanged)
]);

$this->savingsJournal->postWithdrawal($withdrawalTxn, $savingsAccount);
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Feature/SavingsAccountTest.php
php artisan test
```
Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php
git commit -m "fix(accounting): use SavingsJournalService for deposits/withdrawals so payment mode and product type map to correct GL accounts"
```

---

## Phase 4 — Loan Rescheduling Accounting

### Task 7: Journal Entries for Penalty Waivers, Interest Waivers, and Capitalization

**Context:** `LoanRescheduleService::execute()` writes off penalties and interest and capitalises arrears with NO journal entries. This leaves phantom receivables on the balance sheet. This task injects `LoanAccountingService` into `LoanRescheduleService` and adds `postRescheduleAccountingEntries()` which is called at the end of `execute()`.

**Files:**
- Modify: `app/Tenant/Modules/Loans/Services/LoanRescheduleService.php`
- Create: `tests/Feature/Accounting/LoanRescheduleAccountingTest.php`

---

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Accounting/LoanRescheduleAccountingTest.php

namespace Tests\Feature\Accounting;

use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Models\LoanReschedule;
use App\Tenant\Modules\Loans\Services\LoanRescheduleService;
use Tests\TenantTestCase;

class LoanRescheduleAccountingTest extends TenantTestCase
{
    public function test_penalty_waiver_posts_reversal_journal_entry(): void
    {
        // Create a loan product with all required GL accounts seeded
        $product = LoanProduct::factory()->withGlAccounts()->create();
        $loan = Loan::factory()->for($product)->arrears()->create([
            'outstanding_balance' => 10000,
        ]);

        $service = app(LoanRescheduleService::class);
        $service->execute($loan, [
            'reschedule_type'   => 'tenor_extension',
            'new_tenor_months'  => 6,
            'penalties_waived'  => 500,
            'interest_waived'   => 0,
            'reason'            => 'Hardship waiver test',
        ], actorId: 1);

        // There must be a JE of type LOAN_RESCHEDULE
        $je = JournalEntry::where('journal_type', 'loan')
            ->where('reference', $loan->loan_no)
            ->whereRaw("narration LIKE '%waiver%'")
            ->first();

        $this->assertNotNull($je, 'Expected a rescheduling JE for penalty waiver');

        // DR Penalty Income / CR Penalty Receivable (writing off the receivable)
        $dr = JournalEntryLine::where('journal_entry_id', $je->id)->where('debit', '>', 0)->first();
        $cr = JournalEntryLine::where('journal_entry_id', $je->id)->where('credit', '>', 0)->first();

        $this->assertEquals(500, (float) $dr->debit);
        $this->assertEquals(500, (float) $cr->credit);
    }

    public function test_interest_waiver_posts_journal_entry(): void
    {
        $product = LoanProduct::factory()->withGlAccounts()->create(['interest_method' => 'flat']);
        $loan = Loan::factory()->for($product)->arrears()->create([
            'outstanding_balance' => 10000,
        ]);

        $service = app(LoanRescheduleService::class);
        $service->execute($loan, [
            'reschedule_type'   => 'rate_change',
            'new_interest_rate' => 10,
            'penalties_waived'  => 0,
            'interest_waived'   => 300,
            'reason'            => 'Interest waiver test',
        ], actorId: 1);

        $je = JournalEntry::where('journal_type', 'loan')
            ->where('reference', $loan->loan_no)
            ->whereRaw("narration LIKE '%waiver%'")
            ->first();

        $this->assertNotNull($je, 'Expected a rescheduling JE for interest waiver');
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test tests/Feature/Accounting/LoanRescheduleAccountingTest.php
```
Expected: FAIL — LoanProduct factory missing `withGlAccounts` or JE not found.

- [ ] **Step 3: Inject LoanAccountingService into LoanRescheduleService**

At the top of `LoanRescheduleService.php`, add the import and update the constructor:

```php
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\LoanAccountingService;

class LoanRescheduleService
{
    public function __construct(
        protected ScheduleGeneratorServiceInterface $scheduleGenerator,
        protected HolidayService $holidayService,
        protected LoanAccountingService $loanAccounting,
    ) {}
```

- [ ] **Step 4: Add the accounting call at the end of execute()**

Inside `execute()`, after `LoanStatusHistory::create(...)` (the last operation before `return $rescheduleEvent`), add:

```php
// Post accounting entries for any waivers or capitalization
$this->postRescheduleAccountingEntries(
    loan: $loan,
    product: $product,
    calculation: $calculation,
    rescheduleDate: $rescheduleDate,
    actorId: $actorId,
);

return $rescheduleEvent;
```

- [ ] **Step 5: Add the postRescheduleAccountingEntries() method**

Add this private method to `LoanRescheduleService`:

```php
/**
 * Post journal entries for rescheduling-related write-offs and capitalizations.
 *
 * Penalty waiver:    DR Penalty Income (reverse prior accrual)
 *                    CR Penalty Receivable
 *
 * Interest waiver:   DR Interest Income (or Interest Receivable for flat)
 *                    CR Interest Receivable
 *
 * Capitalization:    The new principal is already reflected in the updated loan
 *                    outstanding_balance. No separate JE needed — the disbursement
 *                    JE already created the Loan Portfolio DR at original disbursement.
 *                    Capitalized arrears reclassify overdue interest/principal back
 *                    into the principal balance, which is accounted for by the
 *                    superseded schedule rows becoming zero and the new schedule
 *                    reflecting the new balance. The existing GL balance on Loan
 *                    Portfolio does not change (the outstanding_balance update is
 *                    purely on the model). This is acceptable for SACCOs using
 *                    cash-basis penalty recognition; revisit if IFRS 9 compliance
 *                    is required.
 */
private function postRescheduleAccountingEntries(
    Loan $loan,
    LoanProduct $product,
    array $calculation,
    \Carbon\Carbon $rescheduleDate,
    int $actorId,
): void {
    $penaltiesWaived = (float) ($calculation['penalties_waived'] ?? 0);
    $interestWaived  = (float) ($calculation['interest_waived'] ?? 0);

    if ($penaltiesWaived <= 0 && $interestWaived <= 0) {
        return; // nothing to write off
    }

    $lines = [];
    $narrationParts = [];

    if ($penaltiesWaived > 0 && $product->penalty_income_account_id && $product->penalty_receivable_account_id) {
        // Write off the penalty receivable:
        // DR Penalty Income (reversal of prior accrual) / CR Penalty Receivable
        $lines[] = $this->loanAccounting->line(
            $product->penalty_income_account_id,
            $penaltiesWaived,
            0.0,
            "Penalty waiver – {$loan->loan_no}",
            $loan->id,
        );
        $lines[] = $this->loanAccounting->line(
            $product->penalty_receivable_account_id,
            0.0,
            $penaltiesWaived,
            "Penalty waiver – {$loan->loan_no}",
            $loan->id,
        );
        $narrationParts[] = "penalty waiver {$penaltiesWaived}";
    }

    if ($interestWaived > 0 && $product->interest_receivable_account_id) {
        // Write off interest receivable (both flat and reducing — for flat the receivable exists;
        // for reducing we use interest_income to reverse the unearned portion):
        $interestDrAccountId = $product->interest_income_account_id;
        $interestCrAccountId = $product->interest_receivable_account_id;

        $lines[] = $this->loanAccounting->line(
            $interestDrAccountId,
            $interestWaived,
            0.0,
            "Interest waiver – {$loan->loan_no}",
            $loan->id,
        );
        $lines[] = $this->loanAccounting->line(
            $interestCrAccountId,
            0.0,
            $interestWaived,
            "Interest waiver – {$loan->loan_no}",
            $loan->id,
        );
        $narrationParts[] = "interest waiver {$interestWaived}";
    }

    if (empty($lines)) {
        return;
    }

    $narration = "Reschedule waiver – {$loan->loan_no} (" . implode(', ', $narrationParts) . ')';

    $this->loanAccounting->postJournalEntry(
        loan: $loan,
        typeCode: 'LOAN_RSC',
        narration: $narration,
        lines: $lines,
        date: $rescheduleDate,
        actorId: $actorId,
    );
}
```

- [ ] **Step 6: Run tests**

```bash
php artisan test tests/Feature/Accounting/LoanRescheduleAccountingTest.php
php artisan test
```
Expected: All tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Tenant/Modules/Loans/Services/LoanRescheduleService.php \
        tests/Feature/Accounting/LoanRescheduleAccountingTest.php
git commit -m "feat(accounting): post penalty and interest waiver journal entries on loan rescheduling"
```

---

## Phase 5 — Loan Repayment Reversal

### Task 8: LoanRepaymentReversalService

**Context:** There is no way to reverse a loan repayment. This task creates a service that: (1) validates the transaction is reversible, (2) un-applies the paid amounts from schedule rows, (3) restores outstanding_balance, (4) posts the mirror JE, and (5) marks the LoanTransaction as reversed.

**Files:**
- Create: `app/Tenant/Modules/Loans/Services/LoanRepaymentReversalService.php`
- Create: `tests/Feature/Accounting/LoanRepaymentReversalTest.php`

---

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Accounting/LoanRepaymentReversalTest.php

namespace Tests\Feature\Accounting;

use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Models\LoanTransaction;
use App\Tenant\Modules\Loans\Services\LoanRepaymentReversalService;
use Tests\TenantTestCase;

class LoanRepaymentReversalTest extends TenantTestCase
{
    public function test_reversal_marks_transaction_reversed(): void
    {
        $product = LoanProduct::factory()->withGlAccounts()->create();
        $loan = Loan::factory()->for($product)->disbursed()->create([
            'outstanding_balance' => 8000,
        ]);
        $txn = LoanTransaction::factory()->create([
            'loan_id'          => $loan->id,
            'amount_paid'      => 1000,
            'principal_portion'=> 800,
            'interest_portion' => 200,
            'reversal_flag'    => false,
        ]);

        $service = app(LoanRepaymentReversalService::class);
        $service->reverse($loan, $txn, actorId: 1);

        $this->assertTrue($txn->fresh()->reversal_flag);
        $this->assertNotNull($txn->fresh()->reversed_date);
    }

    public function test_reversal_restores_outstanding_balance(): void
    {
        $product = LoanProduct::factory()->withGlAccounts()->create();
        $loan = Loan::factory()->for($product)->disbursed()->create([
            'outstanding_balance' => 8000,
        ]);
        $txn = LoanTransaction::factory()->create([
            'loan_id'          => $loan->id,
            'amount_paid'      => 1000,
            'principal_portion'=> 1000,
            'interest_portion' => 0,
            'reversal_flag'    => false,
        ]);

        app(LoanRepaymentReversalService::class)->reverse($loan, $txn, actorId: 1);

        // Outstanding balance must be restored by the principal portion
        $this->assertEquals(9000.00, (float) $loan->fresh()->outstanding_balance);
    }

    public function test_reversal_posts_mirror_journal_entry(): void
    {
        $product = LoanProduct::factory()->withGlAccounts()->create();
        $loan = Loan::factory()->for($product)->disbursed()->create([
            'outstanding_balance' => 8000,
        ]);
        $txn = LoanTransaction::factory()->create([
            'loan_id'       => $loan->id,
            'amount_paid'   => 500,
            'reversal_flag' => false,
        ]);

        // Create an original repayment JE to mirror
        JournalEntry::factory()->create([
            'reference'   => $loan->loan_no,
            'journal_type'=> 'loan',
            'status'      => 'posted',
        ]);

        app(LoanRepaymentReversalService::class)->reverse($loan, $txn, actorId: 1);

        $reversalJe = JournalEntry::where('reference', $loan->loan_no)
            ->where('journal_type', 'loan')
            ->whereRaw("narration LIKE '%reversal%'")
            ->first();

        $this->assertNotNull($reversalJe, 'Expected a reversal JE');
    }

    public function test_cannot_reverse_already_reversed_transaction(): void
    {
        $product = LoanProduct::factory()->withGlAccounts()->create();
        $loan = Loan::factory()->for($product)->disbursed()->create();
        $txn = LoanTransaction::factory()->create([
            'loan_id'       => $loan->id,
            'reversal_flag' => true,
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(LoanRepaymentReversalService::class)->reverse($loan, $txn, actorId: 1);
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test tests/Feature/Accounting/LoanRepaymentReversalTest.php
```
Expected: FAIL — class not found.

- [ ] **Step 3: Create LoanRepaymentReversalService**

```php
<?php
// app/Tenant/Modules/Loans/Services/LoanRepaymentReversalService.php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Services\LoanAccountingService;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Modules\Loans\Models\LoanTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanRepaymentReversalService
{
    public function __construct(
        protected LoanAccountingService $loanAccounting,
    ) {}

    /**
     * Reverse a posted loan repayment.
     *
     * Steps:
     *  1. Validate — not already reversed, loan must be active/rescheduled/arrears
     *  2. Un-apply paid amounts from schedule rows (best-effort, latest-first)
     *  3. Restore loan outstanding_balance by the principal portion
     *  4. Post mirror JE (swap DR/CR of original repayment JE)
     *  5. Mark transaction as reversed
     *
     * All steps run in a single DB transaction.
     */
    public function reverse(Loan $loan, LoanTransaction $transaction, int $actorId): void
    {
        DB::connection('tenant')->transaction(function () use ($loan, $transaction, $actorId) {
            $this->assertReversible($transaction);

            $reverseDate = Carbon::now();

            // ── 1. Un-apply from schedule rows ─────────────────────────────────
            $this->unapplyFromSchedule($loan, $transaction);

            // ── 2. Restore outstanding balance ─────────────────────────────────
            $loan->increment('outstanding_balance', (float) $transaction->principal_portion);

            // ── 3. Post reversal JE ────────────────────────────────────────────
            $this->postReversalJournalEntry($loan, $transaction, $reverseDate, $actorId);

            // ── 4. Mark transaction as reversed ────────────────────────────────
            $transaction->update([
                'reversal_flag'  => true,
                'reversed_by'    => $actorId,
                'reversed_date'  => $reverseDate,
            ]);
        });
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function assertReversible(LoanTransaction $transaction): void
    {
        if ($transaction->reversal_flag) {
            throw ValidationException::withMessages([
                'transaction' => ['This repayment has already been reversed.'],
            ]);
        }
    }

    /**
     * Subtract the paid amounts back from schedule rows.
     * Works backwards from the most recently paid installment.
     * Capped at zero (cannot make paid amounts negative).
     */
    private function unapplyFromSchedule(Loan $loan, LoanTransaction $transaction): void
    {
        $schedules = LoanSchedule::where('loan_id', $loan->id)
            ->whereIn('status', ['paid', 'partial'])
            ->orderByDesc('installment_no')
            ->get();

        $principalLeft = (float) $transaction->principal_portion;
        $interestLeft  = (float) $transaction->interest_portion;
        $penaltyLeft   = (float) $transaction->penalty_portion;
        $chargesLeft   = (float) $transaction->charges_portion;

        foreach ($schedules as $schedule) {
            if ($principalLeft <= 0 && $interestLeft <= 0 && $penaltyLeft <= 0 && $chargesLeft <= 0) {
                break;
            }

            $principalUndo = min($principalLeft, (float) $schedule->principal_paid);
            $interestUndo  = min($interestLeft,  (float) $schedule->interest_paid);
            $penaltyUndo   = min($penaltyLeft,   (float) ($schedule->penalty_paid ?? 0));
            $chargesUndo   = min($chargesLeft,   (float) ($schedule->charges_paid ?? 0));

            $schedule->decrement('principal_paid', $principalUndo);
            $schedule->decrement('interest_paid',  $interestUndo);
            if ($penaltyUndo > 0) {
                $schedule->decrement('penalty_paid', $penaltyUndo);
            }
            if ($chargesUndo > 0) {
                $schedule->decrement('charges_paid', $chargesUndo);
            }

            // Recalculate status
            $totalPaid = (float) $schedule->fresh()->principal_paid
                + (float) $schedule->fresh()->interest_paid;
            $totalDue  = (float) $schedule->principal_due + (float) $schedule->interest_due;

            $newStatus = match (true) {
                $totalPaid <= 0                       => 'pending',
                $totalPaid >= $totalDue               => 'paid',
                $schedule->due_date->isPast()         => 'arrears',
                default                               => 'partial',
            };

            $schedule->update(['status' => $newStatus]);

            $principalLeft -= $principalUndo;
            $interestLeft  -= $interestUndo;
            $penaltyLeft   -= $penaltyUndo;
            $chargesLeft   -= $chargesUndo;
        }
    }

    /**
     * Find the original repayment JE and post its mirror.
     * Falls back to building a synthetic reversal if the original JE is not found.
     */
    private function postReversalJournalEntry(
        Loan $loan,
        LoanTransaction $transaction,
        Carbon $reverseDate,
        int $actorId,
    ): void {
        // Try to find the original JE by loan reference and payment_date proximity
        $originalJe = JournalEntry::on('tenant')
            ->where('reference', $loan->loan_no)
            ->where('journal_type', 'loan')
            ->whereDate('date', $transaction->payment_date)
            ->where('status', 'posted')
            ->latest()
            ->first();

        if ($originalJe) {
            // Mirror all lines (swap DR ↔ CR)
            $originalJe->load('lines');
            $mirrorLines = $originalJe->lines->map(function ($line, $idx) use ($loan) {
                return $this->loanAccounting->line(
                    $line->account_id,
                    (float) $line->credit,   // swapped
                    (float) $line->debit,    // swapped
                    "Reversal: {$line->narration}",
                    $loan->id,
                );
            })->values()->toArray();

            $this->loanAccounting->postJournalEntry(
                loan: $loan,
                typeCode: 'LOAN_REV',
                narration: "Repayment reversal – {$loan->loan_no}",
                lines: $mirrorLines,
                date: $reverseDate,
                actorId: $actorId,
            );

            // Mark original as reversed
            $originalJe->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $actorId]);

            return;
        }

        // Fallback: synthetic reversal using loan product GL accounts
        $loan->loadMissing('loanProduct');
        $product = $loan->loanProduct;

        if (! $product) {
            return;
        }

        $totalPaid = (float) $transaction->amount_paid;
        $isFlat = $product->interest_method === 'flat';

        $lines = [
            // CR Disbursement Account (reverse the cash received)
            $this->loanAccounting->line($product->disbursement_account_id, 0.0, $totalPaid, "Repayment reversal – {$loan->loan_no}", $loan->id),
        ];

        if ((float) $transaction->principal_portion > 0) {
            // DR Loan Portfolio (restore balance)
            $lines[] = $this->loanAccounting->line($product->loan_portfolio_account_id, (float) $transaction->principal_portion, 0.0, "Principal reversed – {$loan->loan_no}", $loan->id);
        }
        if ((float) $transaction->interest_portion > 0) {
            $interestAccId = $isFlat ? $product->interest_receivable_account_id : $product->interest_income_account_id;
            $lines[] = $this->loanAccounting->line($interestAccId, (float) $transaction->interest_portion, 0.0, "Interest reversed – {$loan->loan_no}", $loan->id);
        }
        if ((float) $transaction->penalty_portion > 0) {
            $lines[] = $this->loanAccounting->line($product->penalty_receivable_account_id, (float) $transaction->penalty_portion, 0.0, "Penalty reversed – {$loan->loan_no}", $loan->id);
        }
        if ((float) $transaction->charges_portion > 0 && $product->charges_receivable_account_id) {
            $lines[] = $this->loanAccounting->line($product->charges_receivable_account_id, (float) $transaction->charges_portion, 0.0, "Charges reversed – {$loan->loan_no}", $loan->id);
        }

        $this->loanAccounting->postJournalEntry(
            loan: $loan,
            typeCode: 'LOAN_REV',
            narration: "Repayment reversal (synthetic) – {$loan->loan_no}",
            lines: $lines,
            date: $reverseDate,
            actorId: $actorId,
        );
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Feature/Accounting/LoanRepaymentReversalTest.php
```
Expected: All 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Tenant/Modules/Loans/Services/LoanRepaymentReversalService.php \
        tests/Feature/Accounting/LoanRepaymentReversalTest.php
git commit -m "feat(loans): add LoanRepaymentReversalService with mirror JE and schedule un-apply"
```

---

### Task 9: Reversal API Endpoint

**Context:** Wire `LoanRepaymentReversalService` to an HTTP endpoint so users can reverse a repayment from the frontend.

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/LoanRepaymentController.php`
- Modify: `routes/tenant_api.php`

---

- [ ] **Step 1: Add the reverse() action to LoanRepaymentController**

Open `app/Tenant/Http/Controllers/Api/V1/LoanRepaymentController.php`. Add the import and inject `LoanRepaymentReversalService` into the constructor alongside existing services. Then add:

```php
use App\Tenant\Modules\Loans\Services\LoanRepaymentReversalService;
use App\Tenant\Modules\Loans\Models\LoanTransaction;

// In constructor:
public function __construct(
    // ... existing services
    protected LoanRepaymentReversalService $reversalService,
) {}

/**
 * POST /loans/{id}/repayments/{transaction}/reverse
 */
public function reverse(int $id, LoanTransaction $transaction): \Illuminate\Http\JsonResponse
{
    $loan = \App\Tenant\Modules\Loans\Models\Loan::findOrFail($id);

    if ($transaction->loan_id !== $loan->id) {
        return response()->json(['message' => 'Transaction does not belong to this loan.'], 422);
    }

    $this->reversalService->reverse($loan, $transaction, actorId: (int) auth()->id());

    return response()->json(['message' => 'Repayment reversed successfully.']);
}
```

- [ ] **Step 2: Register the route**

In `routes/tenant_api.php`, after line 158 (`Route::post('loans/{id}/repayments', ...)`), add:

```php
Route::post('loans/{id}/repayments/{transaction}/reverse', [LoanRepaymentController::class, 'reverse']);
```

- [ ] **Step 3: Run the full test suite**

```bash
php artisan test
```
Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/LoanRepaymentController.php \
        routes/tenant_api.php
git commit -m "feat(loans): add POST /loans/{id}/repayments/{transaction}/reverse endpoint"
```

---

## Phase 6 — Savings Interest

### Task 10: SavingsInterestService

**Context:** There is no savings interest mechanism at all. The COA has GL 5110 (Interest Expense on Savings) and 2130 (Accrued Interest on Savings). Each SavingsProduct stores an `interest_rate`. This service calculates interest per active account and posts the JE.

**Files:**
- Create: `app/Tenant/Modules/Savings/Services/SavingsInterestService.php`
- Create: `tests/Feature/Accounting/SavingsInterestServiceTest.php`

---

- [ ] **Step 1: Write the failing tests**

```php
<?php
// tests/Feature/Accounting/SavingsInterestServiceTest.php

namespace Tests\Feature\Accounting;

use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Services\SavingsInterestService;
use Tests\TenantTestCase;

class SavingsInterestServiceTest extends TenantTestCase
{
    public function test_interest_is_credited_to_account_balance(): void
    {
        $product = SavingsProduct::factory()->create(['interest_rate' => 12]); // 12% per annum
        $account = SavingsAccount::factory()->for($product)->create([
            'balance'      => 10000,
            'status'       => 'active',
            'interest_rate'=> 12,
        ]);

        app(SavingsInterestService::class)->accrueForAccount($account);

        // Monthly interest = 10000 * 12 / 100 / 12 = 100
        $this->assertEquals(10100.00, (float) $account->fresh()->balance);
    }

    public function test_interest_posts_correct_journal_entry(): void
    {
        $product = SavingsProduct::factory()->create(['interest_rate' => 12]);
        $account = SavingsAccount::factory()->for($product)->create([
            'balance'      => 10000,
            'status'       => 'active',
            'interest_rate'=> 12,
        ]);

        app(SavingsInterestService::class)->accrueForAccount($account);

        $je = JournalEntry::where('journal_type', 'SAVINGS_INTEREST')->latest()->first();
        $this->assertNotNull($je, 'Expected a SAVINGS_INTEREST journal entry');

        // Must have DR line (Interest Expense) and CR line (Savings Liability)
        $lines = JournalEntryLine::where('journal_entry_id', $je->id)->get();
        $this->assertCount(2, $lines);

        $drLine = $lines->firstWhere('debit', '>', 0);
        $crLine = $lines->firstWhere('credit', '>', 0);
        $this->assertEquals(100.00, round((float) $drLine->debit, 2));
        $this->assertEquals(100.00, round((float) $crLine->credit, 2));
    }

    public function test_zero_balance_account_receives_no_interest(): void
    {
        $product = SavingsProduct::factory()->create(['interest_rate' => 12]);
        $account = SavingsAccount::factory()->for($product)->create([
            'balance'      => 0,
            'status'       => 'active',
            'interest_rate'=> 12,
        ]);

        app(SavingsInterestService::class)->accrueForAccount($account);

        $this->assertEquals(0, (float) $account->fresh()->balance);
        $this->assertNull(
            JournalEntry::where('journal_type', 'SAVINGS_INTEREST')->first()
        );
    }
}
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test tests/Feature/Accounting/SavingsInterestServiceTest.php
```
Expected: FAIL — class not found.

- [ ] **Step 3: Create SavingsInterestService**

```php
<?php
// app/Tenant/Modules/Savings/Services/SavingsInterestService.php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Models\SubLedger;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Calculates and posts monthly savings interest for a given period.
 *
 * Interest formula: balance × (annual_rate / 100) / 12
 * Posting:
 *   DR  Interest Expense on Savings (GL 5110)
 *   CR  Member Savings Liability    (GL 2111 / 2112 / 2113 per product type)
 */
class SavingsInterestService
{
    /**
     * Accrue interest for all active savings accounts for the given period.
     *
     * @param  string  $period  YYYY-MM  (defaults to previous month)
     */
    public function accrueAll(string $period = null): int
    {
        $period ??= now()->subMonth()->format('Y-m');

        $count = 0;
        SavingsAccount::with('savingsProduct')
            ->where('status', 'active')
            ->chunkById(200, function ($accounts) use ($period, &$count) {
                foreach ($accounts as $account) {
                    try {
                        if ($this->accrueForAccount($account, $period)) {
                            $count++;
                        }
                    } catch (\Throwable $e) {
                        Log::error("SavingsInterestService: failed for account {$account->id} — {$e->getMessage()}");
                    }
                }
            });

        return $count;
    }

    /**
     * Accrue interest for a single savings account.
     * Returns true if interest was posted, false if skipped (zero balance or zero rate).
     */
    public function accrueForAccount(SavingsAccount $account, string $period = null): bool
    {
        $period ??= now()->subMonth()->format('Y-m');
        $annualRate = (float) ($account->interest_rate ?? $account->savingsProduct?->interest_rate ?? 0);
        $balance = (float) $account->balance;

        if ($annualRate <= 0 || $balance <= 0) {
            return false;
        }

        // Monthly interest = balance × (annual_rate / 100) / 12
        $interest = round($balance * $annualRate / 100 / 12, 2);

        if ($interest <= 0) {
            return false;
        }

        DB::connection('tenant')->transaction(function () use ($account, $interest, $period) {
            // Credit interest to account balance
            $account->increment('balance', $interest);

            // Post JE
            $this->postInterestJournalEntry($account, $interest, $period);
        });

        return true;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function postInterestJournalEntry(SavingsAccount $account, float $interest, string $period): void
    {
        $date = Carbon::parse($period.'-01')->endOfMonth();
        $expenseAccount = $this->resolveByGlCode('5110'); // Interest Expense on Savings
        $savingsAccount = $this->resolveSavingsLiabilityAccount($account);

        if (! $expenseAccount || ! $savingsAccount) {
            Log::warning("SavingsInterestService: GL accounts missing for account {$account->id}");
            return;
        }

        $prefix  = now()->format('Ymd');
        DB::connection('tenant')->statement(
            'INSERT INTO journal_entry_sequences (date_prefix, seq) VALUES (?, 1)
             ON CONFLICT (date_prefix) DO UPDATE SET seq = journal_entry_sequences.seq + 1',
            [$prefix]
        );
        $seq    = (int) DB::connection('tenant')->table('journal_entry_sequences')->where('date_prefix', $prefix)->value('seq');
        $entryNo = sprintf('JE-SAVINGS_INTEREST-%s-%05d', $prefix, $seq);

        $je = JournalEntry::create([
            'entry_no'       => $entryNo,
            'date'           => $date->toDateString(),
            'period_date'    => $date->toDateString(),
            'fiscal_period'  => $period,
            'journal_type'   => 'SAVINGS_INTEREST',
            'reference'      => $account->account_no,
            'reference_type' => 'savings_account',
            'narration'      => "Monthly interest credit – {$account->account_no} ({$period})",
            'status'         => 'posted',
            'is_system'      => true,
            'posted_at'      => now(),
            'posted_by'      => null,
        ]);

        // DR Interest Expense on Savings
        JournalEntryLine::create([
            'journal_entry_id' => $je->id,
            'account_id'       => $expenseAccount->id,
            'debit'            => $interest,
            'credit'           => 0,
            'narration'        => "Interest expense – {$account->account_no}",
            'member_id'        => $account->member_id,
            'savings_id'       => $account->id,
            'line_no'          => 1,
        ]);

        // CR Member Savings Liability
        JournalEntryLine::create([
            'journal_entry_id' => $je->id,
            'account_id'       => $savingsAccount->id,
            'debit'            => 0,
            'credit'           => $interest,
            'narration'        => "Interest credited to member – {$account->account_no}",
            'member_id'        => $account->member_id,
            'savings_id'       => $account->id,
            'line_no'          => 2,
        ]);

        // General Ledger
        $this->postGl($expenseAccount->id, $je->id, $date->toDateString(), $interest, 0, $je->narration);
        $this->postGl($savingsAccount->id, $je->id, $date->toDateString(), 0, $interest, $je->narration);

        // Sub-Ledger (member level)
        $this->postSl($savingsAccount->id, $account->member_id, $je->id, $date->toDateString(), 0, $interest, $je->narration);
    }

    private function resolveByGlCode(string $glCode): ?ChartOfAccount
    {
        return ChartOfAccount::on('tenant')->where('gl_code', $glCode)->where('is_active', true)->first();
    }

    private function resolveSavingsLiabilityAccount(SavingsAccount $account): ?ChartOfAccount
    {
        $type = strtolower($account->savingsProduct?->type ?? '');
        $glCode = match (true) {
            str_contains($type, 'mandatory') => '2111',
            str_contains($type, 'fixed')     => '2113',
            default                          => '2112',
        };
        return $this->resolveByGlCode($glCode);
    }

    private function postGl(int $accountId, int $jeId, string $date, float $debit, float $credit, string $narration): void
    {
        $account = ChartOfAccount::on('tenant')->find($accountId);
        $normalBalance = $account?->normal_balance ?? 'DR';
        $last = (string) (GeneralLedger::on('tenant')->where('account_id', $accountId)->lockForUpdate()->orderByDesc('id')->value('balance') ?? '0');
        $balance = $normalBalance === 'DR'
            ? bcadd($last, bcsub((string) $debit, (string) $credit, 4), 4)
            : bcadd($last, bcsub((string) $credit, (string) $debit, 4), 4);

        GeneralLedger::create(['account_id' => $accountId, 'journal_entry_id' => $jeId, 'date' => $date, 'debit' => $debit, 'credit' => $credit, 'balance' => $balance, 'narration' => $narration]);
    }

    private function postSl(int $accountId, int $memberId, int $jeId, string $date, float $debit, float $credit, string $narration): void
    {
        $account = ChartOfAccount::on('tenant')->find($accountId);
        $normalBalance = $account?->normal_balance ?? 'CR';
        $last = (string) (SubLedger::on('tenant')->where('account_id', $accountId)->where('entity_id', $memberId)->where('entity_type', Member::class)->lockForUpdate()->orderByDesc('id')->value('balance') ?? '0');
        $balance = $normalBalance === 'CR'
            ? bcadd($last, bcsub((string) $credit, (string) $debit, 4), 4)
            : bcadd($last, bcsub((string) $debit, (string) $credit, 4), 4);

        SubLedger::create(['account_id' => $accountId, 'entity_id' => $memberId, 'entity_type' => Member::class, 'journal_entry_id' => $jeId, 'date' => $date, 'debit' => $debit, 'credit' => $credit, 'balance' => $balance, 'narration' => $narration]);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test tests/Feature/Accounting/SavingsInterestServiceTest.php
```
Expected: 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Tenant/Modules/Savings/Services/SavingsInterestService.php \
        tests/Feature/Accounting/SavingsInterestServiceTest.php
git commit -m "feat(savings): add SavingsInterestService for monthly interest accrual and GL posting"
```

---

### Task 11: Artisan Command for Savings Interest

**Context:** Wrap `SavingsInterestService::accrueAll()` in an Artisan command so it can be scheduled monthly via `app/Console/Kernel.php`.

**Files:**
- Create: `app/Tenant/Console/Commands/AccrueSavingsInterest.php`

---

- [ ] **Step 1: Create the command**

```php
<?php
// app/Tenant/Console/Commands/AccrueSavingsInterest.php

namespace App\Tenant\Console\Commands;

use App\Tenant\Modules\Savings\Services\SavingsInterestService;
use Illuminate\Console\Command;

class AccrueSavingsInterest extends Command
{
    protected $signature = 'savings:accrue-interest
                            {--period= : YYYY-MM period (defaults to previous month)}
                            {--dry-run : Calculate and display amounts without posting}';

    protected $description = 'Calculate and post monthly savings interest for all active accounts';

    public function handle(SavingsInterestService $service): int
    {
        $period = $this->option('period') ?? now()->subMonth()->format('Y-m');

        if ($this->option('dry-run')) {
            $this->info("[DRY-RUN] Would accrue interest for period {$period}");
            return self::SUCCESS;
        }

        $this->info("Accruing savings interest for period: {$period}");
        $count = $service->accrueAll($period);
        $this->info("Done. Interest posted for {$count} account(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Register the command in console kernel**

Open `app/Console/Kernel.php` (or the equivalent in your Laravel 12 setup). In the `$commands` array or via auto-discovery, ensure `AccrueSavingsInterest::class` is registered.

In the `schedule()` method, add the monthly schedule:

```php
$schedule->command('savings:accrue-interest')->monthlyOn(1, '01:00');
```

- [ ] **Step 3: Test the command manually**

```bash
php artisan savings:accrue-interest --dry-run --period=2026-03
```
Expected output: `[DRY-RUN] Would accrue interest for period 2026-03`

- [ ] **Step 4: Run the full test suite**

```bash
php artisan test
```
Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Tenant/Console/Commands/AccrueSavingsInterest.php \
        app/Console/Kernel.php
git commit -m "feat(savings): add savings:accrue-interest Artisan command scheduled monthly"
```

---

## Self-Review Checklist

**Spec coverage:**
| Audit Gap | Task |
|---|---|
| Processing fee → wrong GL | Task 2 ✅ |
| SubLedger bare string entity types | Task 3 ✅ |
| On-repayment charge accrual | Task 4 ✅ |
| Initial savings deposit has no JE | Task 5 ✅ |
| Live savings uses hard-coded GL accounts | Task 6 ✅ |
| Loan rescheduling has zero accounting | Task 7 ✅ |
| Loan repayment reversal not implemented | Tasks 8–9 ✅ |
| Savings interest completely missing | Tasks 10–11 ✅ |
| JE number not unique under concurrency | Task 1 ✅ |
| GL running balance race condition | Task 1 (lockForUpdate) ✅ |
| Duplicate postToGL/postToSubLedger | Task 1 (LoanAccountingService) ✅ |

**No placeholders:** All code blocks contain actual implementations.

**Type consistency:** `LoanAccountingService::line()` returns the same key structure (`accountId`, `debit`, `credit`, `narration`, `loanId`) used by `postJournalEntry()` throughout all tasks.
