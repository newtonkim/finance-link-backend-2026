# Member Account Statement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a per-savings-account, bank-style member statement tab + endpoint that matches the reference image, computes opening/closing balances on the server, and prints the same DOM (no separate print template).

**Architecture:** A new `SavingsAccountStatementService` (behind an interface) owns the computation: opening balance from pre-period transactions, period rows annotated with `credit`/`debit`/`running_balance`, reconciliation invariant `opening + Σcredit − Σdebit = closing = running_balance(last)`. A thin controller validates input and returns JSON. The frontend `statement.vue` is replaced with a layout that mirrors the image and a `useAccountStatement` composable that re-fetches on filter changes; printing uses the existing `printElementId` helper against the statement DOM.

**Tech Stack:** Laravel 12 + Pest (backend), Vue 3 + Vite + Vitest (frontend), MySQL per-tenant.

---

## File map

**Backend (`mfuko-pro-backend-2026/`):**
- Create `app/Tenant/Modules/Savings/Contracts/SavingsAccountStatementServiceInterface.php` — one-method contract.
- Create `app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php` — all query/computation logic.
- Create `app/Tenant/Http/Controllers/Api/V1/SavingsAccountStatementController.php` — thin controller.
- Modify `app/Providers/AppServiceProvider.php` — bind interface to concrete.
- Modify `routes/tenant_api.php` — add one route near line 86 (next to `member-statement`).
- Create `tests/Tenant/Reports/SavingsAccountStatementServiceTest.php`.
- Create `tests/Tenant/Reports/SavingsAccountStatementControllerTest.php`.

**Frontend (`mfuko-pro-frontend-2026/`):**
- Create `src/tenant/modules/members/profile/composables/useAccountStatement.ts`.
- Replace `src/tenant/modules/members/profile/statement.vue`.
- Create `src/tenant/modules/members/__tests__/statement.spec.ts`.

**Shared credit/debit constants live in the service**, not duplicated on the frontend (the API returns numeric `credit` and `debit` per row; the frontend never re-classifies).

---

## Task 1: Backend — interface + binding (bootstrap)

**Files:**
- Create: `app/Tenant/Modules/Savings/Contracts/SavingsAccountStatementServiceInterface.php`
- Create: `app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Tenant/Reports/SavingsAccountStatementServiceTest.php`

- [ ] **Step 1: Write the failing test (DI resolves to the concrete).**

```php
<?php
// tests/Tenant/Reports/SavingsAccountStatementServiceTest.php

use App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface;
use App\Tenant\Modules\Savings\Services\SavingsAccountStatementService;

it('binds interface to concrete service', function () {
    expect(app(SavingsAccountStatementServiceInterface::class))
        ->toBeInstanceOf(SavingsAccountStatementService::class);
});
```

- [ ] **Step 2: Run the test — expect FAIL.**

```bash
php artisan test --filter='binds interface to concrete service'
```
Expected: `Class "App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface" not found`.

- [ ] **Step 3: Create the interface.**

```php
<?php
// app/Tenant/Modules/Savings/Contracts/SavingsAccountStatementServiceInterface.php

namespace App\Tenant\Modules\Savings\Contracts;

interface SavingsAccountStatementServiceInterface
{
    /**
     * Build a per-savings-account statement.
     *
     * @return array{
     *   account: array, member: array, branch: array,
     *   period: array, balances: array,
     *   transactions: array<int, array>, warnings: array<int, string>
     * }
     */
    public function buildStatement(int $savingsAccountId, ?string $dateFrom = null, ?string $dateTo = null): array;
}
```

- [ ] **Step 4: Create the concrete stub.**

```php
<?php
// app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface;

class SavingsAccountStatementService implements SavingsAccountStatementServiceInterface
{
    public function buildStatement(int $savingsAccountId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return [
            'account' => [], 'member' => [], 'branch' => [],
            'period' => [], 'balances' => [],
            'transactions' => [], 'warnings' => [],
        ];
    }
}
```

- [ ] **Step 5: Add the binding to `AppServiceProvider::register()`.**

In `app/Providers/AppServiceProvider.php`, inside `register()` next to the other Savings/Loans bindings:

```php
$this->app->bind(
    \App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface::class,
    \App\Tenant\Modules\Savings\Services\SavingsAccountStatementService::class,
);
```

- [ ] **Step 6: Run the test — expect PASS.**

```bash
php artisan test --filter='binds interface to concrete service'
```
Expected: `1 passed`.

- [ ] **Step 7: Commit.**

```bash
git add app/Tenant/Modules/Savings/Contracts/ app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php app/Providers/AppServiceProvider.php tests/Tenant/Reports/SavingsAccountStatementServiceTest.php
git commit -m "feat(reports): scaffold SavingsAccountStatementService + interface binding"
```

---

## Task 2: Backend — opening balance

**Files:**
- Modify: `app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php`
- Test: `tests/Tenant/Reports/SavingsAccountStatementServiceTest.php`

- [ ] **Step 1: Write the failing test.** Two pre-period deposits + one pre-period charge → opening balance = `1000 + 500 − 50`.

```php
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Support\Carbon;

it('computes opening balance from pre-period transactions only', function () {
    $account = SavingsAccount::factory()->create(['balance' => 0]);
    $member = $account->member;

    Transaction::create([
        'reference' => 'T1', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'deposit', 'amount' => 1000,
        'transaction_date' => Carbon::parse('2026-01-05'),
    ]);
    Transaction::create([
        'reference' => 'T2', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'deposit', 'amount' => 500,
        'transaction_date' => Carbon::parse('2026-01-20'),
    ]);
    Transaction::create([
        'reference' => 'T3', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'charge', 'amount' => 50,
        'transaction_date' => Carbon::parse('2026-01-25'),
    ]);
    // In-period row that must NOT count toward opening:
    Transaction::create([
        'reference' => 'T4', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'deposit', 'amount' => 9999,
        'transaction_date' => Carbon::parse('2026-02-15'),
    ]);

    $result = app(\App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($result['balances']['opening'])->toBe(1450.00);
});
```

- [ ] **Step 2: Run the test — expect FAIL** (`opening` is missing from the stub).

```bash
php artisan test --filter='computes opening balance from pre-period transactions only'
```

- [ ] **Step 3: Implement opening balance.** Replace the body of `buildStatement` with:

```php
public function buildStatement(int $savingsAccountId, ?string $dateFrom = null, ?string $dateTo = null): array
{
    $dateTo   = $dateTo   ?? now()->toDateString();
    $dateFrom = $dateFrom ?? now()->subDays(90)->toDateString();

    $opening = $this->sumCreditsMinusDebits(
        $this->baseQuery($savingsAccountId)->whereDate('transaction_date', '<', $dateFrom)->get()
    );

    return [
        'account' => [], 'member' => [], 'branch' => [],
        'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'statement_date' => now()->toDateString()],
        'balances' => [
            'opening' => round($opening, 2),
            'total_credit' => 0.0, 'total_debit' => 0.0, 'closing' => round($opening, 2), 'count' => 0,
        ],
        'transactions' => [], 'warnings' => [],
    ];
}

private function baseQuery(int $savingsAccountId): \Illuminate\Database\Eloquent\Builder
{
    return \App\Tenant\Modules\Transactions\Models\Transaction::query()
        ->where('savings_account_id', $savingsAccountId)
        ->where('is_reversed', 0)
        ->whereNull('deleted_at');
}

private function sumCreditsMinusDebits(\Illuminate\Support\Collection $rows): float
{
    $sum = 0.0;
    foreach ($rows as $r) {
        [$c, $d] = $this->classify($r);
        $sum += $c - $d;
    }
    return $sum;
}

/** Returns [credit, debit] from a Transaction row. */
private function classify(\App\Tenant\Modules\Transactions\Models\Transaction $r): array
{
    $type = (string) $r->type;
    $amount = (float) $r->amount;
    $chargeAmount = (float) ($r->charge_amount ?? 0);

    if (in_array($type, ['deposit', 'transfer_in', 'interest'], true)) {
        return [$amount, 0.0];
    }
    if (in_array($type, ['withdrawal', 'withdraw', 'transfer_out'], true)) {
        return [0.0, $amount];
    }
    if (in_array($type, ['charge', 'general-charge'], true)) {
        return [0.0, $amount];
    }
    if (in_array($type, ['deposit-charge', 'withdraw-charge'], true)) {
        return [0.0, $amount > 0 ? $amount : $chargeAmount];
    }
    return [0.0, 0.0]; // unknown type — caller emits warning
}
```

- [ ] **Step 4: Run the test — expect PASS.**

- [ ] **Step 5: Commit.**

```bash
git add app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php tests/Tenant/Reports/SavingsAccountStatementServiceTest.php
git commit -m "feat(reports): compute opening balance from pre-period transactions"
```

---

## Task 3: Backend — period transactions + credit/debit + running balance + reconciliation

**Files:**
- Modify: `app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php`
- Test: `tests/Tenant/Reports/SavingsAccountStatementServiceTest.php`

- [ ] **Step 1: Write the failing test.** A small fixture exercising each branch of `classify()` and the reconciliation invariant.

```php
it('annotates period rows with credit/debit/running_balance and reconciles totals', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;

    // Pre-period: opening = 100
    Transaction::create([
        'reference' => 'A', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'deposit', 'amount' => 100,
        'transaction_date' => Carbon::parse('2026-01-31'),
    ]);
    // Period: +500 deposit, +50 charge (debit), -200 withdrawal, +6000 deposit-charge (debit, charge_amount used)
    Transaction::create([
        'reference' => 'B', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'deposit', 'amount' => 500,
        'transaction_date' => Carbon::parse('2026-02-01'),
    ]);
    Transaction::create([
        'reference' => 'C', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'charge', 'amount' => 50,
        'transaction_date' => Carbon::parse('2026-02-02'),
    ]);
    Transaction::create([
        'reference' => 'D', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'withdrawal', 'amount' => 200,
        'transaction_date' => Carbon::parse('2026-02-03'),
    ]);
    Transaction::create([
        'reference' => 'E', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'deposit-charge',
        'amount' => 0, 'charge_amount' => 6000,
        'transaction_date' => Carbon::parse('2026-02-04'),
    ]);

    $r = app(\App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['balances']['opening'])->toBe(100.00);
    expect($r['balances']['total_credit'])->toBe(500.00);
    expect($r['balances']['total_debit'])->toBe(6250.00); // 50 + 200 + 6000
    expect($r['balances']['closing'])->toBe(-5650.00);    // 100 + 500 - 6250
    expect($r['balances']['count'])->toBe(4);

    // Running balance reconciles
    $last = end($r['transactions']);
    expect($last['running_balance'])->toBe(-5650.00);

    // Mapping spot-checks
    $byRef = collect($r['transactions'])->keyBy('id');
    $deposit = $r['transactions'][0];
    expect($deposit['credit'])->toBe(500.00)->and($deposit['debit'])->toBe(0.0);
    $depositCharge = end($r['transactions']);
    expect($depositCharge['debit'])->toBe(6000.00); // charge_amount used because amount=0
});
```

- [ ] **Step 2: Run the test — expect FAIL.**

- [ ] **Step 3: Implement period processing.** Replace the body of `buildStatement` with:

```php
public function buildStatement(int $savingsAccountId, ?string $dateFrom = null, ?string $dateTo = null): array
{
    $dateTo   = $dateTo   ?? now()->toDateString();
    $dateFrom = $dateFrom ?? now()->subDays(90)->toDateString();

    $opening = $this->sumCreditsMinusDebits(
        $this->baseQuery($savingsAccountId)->whereDate('transaction_date', '<', $dateFrom)->get()
    );

    $periodRows = $this->baseQuery($savingsAccountId)
        ->whereBetween('transaction_date', [$dateFrom, $dateTo])
        ->orderBy('transaction_date')->orderBy('id')
        ->get();

    $running = $opening;
    $totalCredit = 0.0;
    $totalDebit  = 0.0;
    $warnings    = [];
    $rows        = [];

    foreach ($periodRows as $r) {
        [$credit, $debit] = $this->classify($r);
        if ($credit === 0.0 && $debit === 0.0 && ! in_array($r->type, ['deposit', 'transfer_in', 'interest', 'withdrawal', 'withdraw', 'transfer_out', 'charge', 'general-charge', 'deposit-charge', 'withdraw-charge'], true)) {
            $warnings[] = "Unknown transaction type '{$r->type}' on row {$r->id} — excluded.";
            continue;
        }
        $running += $credit - $debit;
        $totalCredit += $credit;
        $totalDebit  += $debit;
        $rows[] = [
            'id'              => $r->id,
            'date'            => (string) $r->transaction_date,
            'description'     => $this->describe($r),
            'credit'          => round($credit, 2),
            'debit'           => round($debit, 2),
            'running_balance' => round($running, 2),
            'is_reversal'     => ! empty($r->reversal_of),
        ];
    }

    $closing = $opening + $totalCredit - $totalDebit;
    $this->assertReconciles($closing, $rows);

    return [
        'account' => [], 'member' => [], 'branch' => [],
        'period' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'statement_date' => now()->toDateString()],
        'balances' => [
            'opening'      => round($opening, 2),
            'total_credit' => round($totalCredit, 2),
            'total_debit'  => round($totalDebit, 2),
            'closing'      => round($closing, 2),
            'count'        => count($rows),
        ],
        'transactions' => $rows,
        'warnings'     => $warnings,
    ];
}

private function describe(\App\Tenant\Modules\Transactions\Models\Transaction $r): string
{
    static $labels = [
        'deposit' => 'Savings Deposit',
        'withdrawal' => 'Savings Withdrawal',
        'withdraw' => 'Savings Withdrawal',
        'transfer_in' => 'Account Transfer In',
        'transfer_out' => 'Account Transfer Out',
        'charge' => 'Charge',
        'general-charge' => 'General Charge',
        'deposit-charge' => 'Deposit Charge',
        'withdraw-charge' => 'Withdrawal Charge',
        'interest' => 'Interest',
    ];
    $label = $labels[$r->type] ?? ucwords(str_replace(['_', '-'], ' ', (string) $r->type));
    $narration = trim((string) ($r->narration ?? ''));
    return $narration === '' ? $label : "{$label} — {$narration}";
}

private function assertReconciles(float $closing, array $rows): void
{
    if (count($rows) === 0) {
        return;
    }
    $last = end($rows)['running_balance'];
    if (abs($closing - $last) > 0.01) {
        throw new \RuntimeException("Statement does not reconcile: closing={$closing}, running_balance(last)={$last}");
    }
}
```

- [ ] **Step 4: Run the test — expect PASS.**

- [ ] **Step 5: Commit.**

```bash
git add app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php tests/Tenant/Reports/SavingsAccountStatementServiceTest.php
git commit -m "feat(reports): annotate period rows with credit/debit/running balance and reconcile totals"
```

---

## Task 4: Backend — edge cases (soft-deletes, reversed originals, unknown types)

**Files:**
- Modify: `app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php` (no changes expected; the test asserts correct exclusion)
- Test: `tests/Tenant/Reports/SavingsAccountStatementServiceTest.php`

- [ ] **Step 1: Write the failing tests.**

```php
it('excludes soft-deleted transactions', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;
    $row = Transaction::create([
        'reference' => 'X', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'deposit', 'amount' => 999,
        'transaction_date' => Carbon::parse('2026-02-10'),
    ]);
    $row->delete(); // soft-delete

    $r = app(\App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['balances']['count'])->toBe(0);
    expect($r['balances']['total_credit'])->toBe(0.0);
});

it('excludes reversed originals and includes the reversal entry', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;

    $original = Transaction::create([
        'reference' => 'ORIG', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'deposit', 'amount' => 1000,
        'transaction_date' => Carbon::parse('2026-02-05'),
        'is_reversed' => true,  // marked as reversed
    ]);
    Transaction::create([
        'reference' => 'REV', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'withdrawal', 'amount' => 1000,
        'transaction_date' => Carbon::parse('2026-02-06'),
        'reversal_of' => $original->id,  // is the reversal entry
        'is_reversed' => false,
    ]);

    $r = app(\App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['balances']['count'])->toBe(1);                  // only the reversal entry
    expect($r['transactions'][0]['is_reversal'])->toBeTrue();
    expect($r['balances']['total_debit'])->toBe(1000.00);
    expect($r['balances']['total_credit'])->toBe(0.0);
});

it('warns about unknown transaction types and excludes them from totals', function () {
    $account = SavingsAccount::factory()->create();
    $member = $account->member;
    Transaction::create([
        'reference' => 'U', 'member_id' => $member->id, 'account_id' => $account->id,
        'savings_account_id' => $account->id, 'type' => 'mystery_type', 'amount' => 777,
        'transaction_date' => Carbon::parse('2026-02-15'),
    ]);

    $r = app(\App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['warnings'])->toHaveCount(1);
    expect($r['balances']['count'])->toBe(0);
});
```

- [ ] **Step 2: Run the tests — expect PASS** (the service already handles these via `baseQuery` filters and `classify()`'s fallback). If any fail, fix in-place.

```bash
php artisan test --filter='excludes soft-deleted|excludes reversed originals|warns about unknown'
```

- [ ] **Step 3: Commit.**

```bash
git add tests/Tenant/Reports/SavingsAccountStatementServiceTest.php
git commit -m "test(reports): cover soft-deletes, reversals, and unknown types"
```

---

## Task 5: Backend — populate account / member / branch in the response

**Files:**
- Modify: `app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php`
- Test: `tests/Tenant/Reports/SavingsAccountStatementServiceTest.php`

- [ ] **Step 1: Write the failing test.**

```php
it('populates account, member, and branch metadata', function () {
    $branch = \DB::table('branches')->insertGetId(['name' => 'Main branch', 'created_at' => now(), 'updated_at' => now()]);
    $member = \App\Models\Member::create([
        'name' => 'Maya Nyamu', 'member_number' => 'MBRC-001',
        'address' => 'Kibada St, STE 108', 'branch_id' => $branch, 'status' => 'active',
    ]);
    $product = \DB::table('savings_products')->insertGetId(['name' => 'General Savings', 'type' => 'voluntary', 'created_at' => now(), 'updated_at' => now()]);
    $account = SavingsAccount::create([
        'member_id' => $member->id, 'savings_product_id' => $product, 'account_no' => 'SA-001',
        'account_type' => 'voluntary', 'balance' => 0, 'branch_id' => $branch, 'status' => 'active',
    ]);

    $r = app(\App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface::class)
        ->buildStatement($account->id, '2026-02-01', '2026-02-28');

    expect($r['account']['account_no'])->toBe('SA-001');
    expect($r['account']['account_type'])->toBe('Voluntary');
    expect($r['account']['product_name'])->toBe('General Savings');
    expect($r['member']['name'])->toBe('Maya Nyamu');
    expect($r['member']['member_number'])->toBe('MBRC-001');
    expect($r['member']['address'])->toBe('Kibada St, STE 108');
    expect($r['branch']['name'])->toBe('Main branch');
});
```

- [ ] **Step 2: Run the test — expect FAIL.**

- [ ] **Step 3: Implement metadata loading.** Add to `buildStatement` (before the opening-balance computation), and add 404 behavior:

```php
$account = \App\Tenant\Modules\Savings\Models\SavingsAccount::with(['member.branch', 'savingsProduct'])
    ->whereNull('deleted_at')
    ->find($savingsAccountId);

if (! $account) {
    throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException("Savings account {$savingsAccountId} not found.");
}

$accountMeta = [
    'id'           => $account->id,
    'account_no'   => $account->account_no,
    'account_type' => ucwords((string) $account->account_type),
    'product_name' => $account->savingsProduct?->name,
];
$memberMeta = [
    'id'            => $account->member?->id,
    'name'          => $account->member?->name,
    'member_number' => $account->member?->member_number,
    'address'       => $account->member?->address,
    'address_city'  => null,
];
$branchMeta = ['name' => $account->member?->branch?->name];
```

Then in the return array, replace the empty `account/member/branch` with `$accountMeta/$memberMeta/$branchMeta`.

- [ ] **Step 4: Run the test — expect PASS.**

- [ ] **Step 5: Commit.**

```bash
git add app/Tenant/Modules/Savings/Services/SavingsAccountStatementService.php tests/Tenant/Reports/SavingsAccountStatementServiceTest.php
git commit -m "feat(reports): include account, member, and branch metadata in statement"
```

---

## Task 6: Backend — controller, route, and 404 / 422 / happy-path tests

**Files:**
- Create: `app/Tenant/Http/Controllers/Api/V1/SavingsAccountStatementController.php`
- Modify: `routes/tenant_api.php` (add route near line 86)
- Create: `tests/Tenant/Reports/SavingsAccountStatementControllerTest.php`

- [ ] **Step 1: Write the failing tests (controller).**

```php
<?php
// tests/Tenant/Reports/SavingsAccountStatementControllerTest.php

use App\Tenant\Modules\Savings\Models\SavingsAccount;

it('returns 404 for missing account', function () {
    $this->actingAsTenantUser()
        ->getJson('/api/v1/tenant/reports/savings-account-statement/999999')
        ->assertStatus(404);
});

it('returns 422 on invalid date params', function () {
    $account = SavingsAccount::factory()->create();
    $this->actingAsTenantUser()
        ->getJson("/api/v1/tenant/reports/savings-account-statement/{$account->id}?date_from=not-a-date")
        ->assertStatus(422);
});

it('returns the documented JSON shape', function () {
    $account = SavingsAccount::factory()->create();
    $this->actingAsTenantUser()
        ->getJson("/api/v1/tenant/reports/savings-account-statement/{$account->id}")
        ->assertOk()
        ->assertJsonStructure([
            'account'  => ['id', 'account_no', 'account_type', 'product_name'],
            'member'   => ['id', 'name', 'member_number', 'address', 'address_city'],
            'branch'   => ['name'],
            'period'   => ['date_from', 'date_to', 'statement_date'],
            'balances' => ['opening', 'total_credit', 'total_debit', 'closing', 'count'],
            'transactions',
            'warnings',
        ]);
});
```

> Note: `actingAsTenantUser()` is the existing tenant auth helper used elsewhere in `tests/Tenant/`; if its name differs in this repo, replace with the equivalent (check `tests/Tenant/Accounting/` for a working pattern).

- [ ] **Step 2: Run the tests — expect FAIL** (route not defined).

```bash
php artisan test --filter='savings-account-statement|SavingsAccountStatementController'
```

- [ ] **Step 3: Create the controller.**

```php
<?php
// app/Tenant/Http/Controllers/Api/V1/SavingsAccountStatementController.php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavingsAccountStatementController extends Controller
{
    public function __construct(private readonly SavingsAccountStatementServiceInterface $service) {}

    public function show(Request $request, int $savingsAccountId): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return response()->json($this->service->buildStatement(
            $savingsAccountId,
            $validated['date_from'] ?? null,
            $validated['date_to']   ?? null,
        ));
    }
}
```

- [ ] **Step 4: Add the route.** In `routes/tenant_api.php`, near line 86 (next to `reports/member-statement`):

```php
use App\Tenant\Http\Controllers\Api\V1\SavingsAccountStatementController;
// ...
Route::get('reports/savings-account-statement/{savingsAccountId}', [SavingsAccountStatementController::class, 'show'])
    ->whereNumber('savingsAccountId');
```

- [ ] **Step 5: Run the tests — expect PASS.**

```bash
php artisan test --filter='SavingsAccountStatementController'
```

- [ ] **Step 6: Commit.**

```bash
git add app/Tenant/Http/Controllers/Api/V1/SavingsAccountStatementController.php routes/tenant_api.php tests/Tenant/Reports/SavingsAccountStatementControllerTest.php
git commit -m "feat(reports): add savings-account-statement endpoint with validation and 404 handling"
```

---

## Task 7: Frontend — `useAccountStatement` composable

**Files:**
- Create: `src/tenant/modules/members/profile/composables/useAccountStatement.ts`

- [ ] **Step 1: Write the failing test.**

```ts
// src/tenant/modules/members/__tests__/useAccountStatement.spec.ts
import { describe, it, expect, vi } from 'vitest';
import { ref, nextTick } from 'vue';
import { useAccountStatement } from '../profile/composables/useAccountStatement';

vi.mock('@/tenant/apis/tenantClient', () => ({
  tenantClient: {
    get: vi.fn(() => Promise.resolve({ data: {
      account: { id: 1, account_no: 'SA-1', account_type: 'Voluntary', product_name: 'X' },
      member: { id: 1, name: 'Maya', member_number: 'M1', address: 'A', address_city: null },
      branch: { name: 'Main' },
      period: { date_from: '2026-02-01', date_to: '2026-02-28', statement_date: '2026-02-28' },
      balances: { opening: 0, total_credit: 100, total_debit: 0, closing: 100, count: 1 },
      transactions: [{ id: 1, date: '2026-02-15', description: 'Savings Deposit', credit: 100, debit: 0, running_balance: 100, is_reversal: false }],
      warnings: [],
    }})),
  },
}));

describe('useAccountStatement', () => {
  it('fetches and exposes the statement reactive state', async () => {
    const accountId = ref<number | null>(1);
    const from = ref('2026-02-01');
    const to = ref('2026-02-28');

    const { statement, loading, error, refresh } = useAccountStatement(accountId, from, to);

    await refresh();
    expect(loading.value).toBe(false);
    expect(error.value).toBeNull();
    expect(statement.value?.balances.closing).toBe(100);
  });
});
```

- [ ] **Step 2: Run the test — expect FAIL.**

```bash
pnpm test:unit -- useAccountStatement
```

- [ ] **Step 3: Implement the composable.**

```ts
// src/tenant/modules/members/profile/composables/useAccountStatement.ts
import { ref, watch, Ref } from 'vue';
import { tenantClient } from '@/tenant/apis/tenantClient';

export interface StatementTransaction {
  id: number;
  date: string;
  description: string;
  credit: number;
  debit: number;
  running_balance: number;
  is_reversal: boolean;
}

export interface AccountStatement {
  account:  { id: number; account_no: string | null; account_type: string; product_name: string | null };
  member:   { id: number; name: string; member_number: string; address: string | null; address_city: string | null };
  branch:   { name: string | null };
  period:   { date_from: string; date_to: string; statement_date: string };
  balances: { opening: number; total_credit: number; total_debit: number; closing: number; count: number };
  transactions: StatementTransaction[];
  warnings: string[];
}

export function useAccountStatement(
  accountId: Ref<number | null>,
  dateFrom: Ref<string>,
  dateTo: Ref<string>,
) {
  const statement = ref<AccountStatement | null>(null);
  const loading = ref(false);
  const error = ref<string | null>(null);

  async function refresh() {
    if (!accountId.value) { statement.value = null; return; }
    loading.value = true;
    error.value = null;
    try {
      const { data } = await tenantClient.get(
        `/reports/savings-account-statement/${accountId.value}`,
        { params: { date_from: dateFrom.value, date_to: dateTo.value } },
      );
      statement.value = data as AccountStatement;
    } catch (e: any) {
      error.value = e?.response?.data?.message ?? 'Could not load statement.';
      statement.value = null;
    } finally {
      loading.value = false;
    }
  }

  watch([accountId, dateFrom, dateTo], () => { refresh(); }, { immediate: true });

  return { statement, loading, error, refresh };
}
```

- [ ] **Step 4: Run the test — expect PASS.**

- [ ] **Step 5: Commit.**

```bash
git add src/tenant/modules/members/profile/composables/useAccountStatement.ts src/tenant/modules/members/__tests__/useAccountStatement.spec.ts
git commit -m "feat(members): add useAccountStatement composable"
```

---

## Task 8: Frontend — replace `statement.vue` (layout matching the image)

**Files:**
- Replace: `src/tenant/modules/members/profile/statement.vue`

- [ ] **Step 1: Write the file.**

```vue
<script setup lang="ts">
import { ref, computed } from 'vue';
import { Printer } from 'lucide-vue-next';
import { formatCurrency, printElementId } from '@/Global';
import { useAccountStatement } from './composables/useAccountStatement';

const props = defineProps<{
  data?: { savings_accounts?: Array<{ id: number; account_no: string | null; account_type: string }> };
  profileDetails?: any;
}>();

const accounts = computed(() => props.data?.savings_accounts ?? []);
const accountId = ref<number | null>(accounts.value[0]?.id ?? null);

const today = new Date().toISOString().slice(0, 10);
const ninetyDaysAgo = new Date(Date.now() - 90 * 86_400_000).toISOString().slice(0, 10);
const dateFrom = ref(ninetyDaysAgo);
const dateTo   = ref(today);

const { statement, loading, error, refresh } = useAccountStatement(accountId, dateFrom, dateTo);

function onPrint() { printElementId('statement-print-area'); }

function fmtDate(d?: string) {
  if (!d) return '';
  const [y, m, day] = d.split('-');
  return `${m}/${day}/${y}`;
}
</script>

<template>
  <div>
    <!-- Controls (hidden on print) -->
    <div class="no-print flex items-end gap-3 my-4">
      <label class="text-xs font-bold uppercase tracking-wider">
        Account
        <select v-model="accountId" class="block mt-1 border rounded px-3 py-2 text-sm">
          <option v-for="a in accounts" :key="a.id" :value="a.id">
            {{ a.account_no ?? `Account #${a.id}` }} ({{ a.account_type }})
          </option>
        </select>
      </label>
      <label class="text-xs font-bold uppercase tracking-wider">
        From
        <input type="date" v-model="dateFrom" class="block mt-1 border rounded px-3 py-2 text-sm" />
      </label>
      <label class="text-xs font-bold uppercase tracking-wider">
        To
        <input type="date" v-model="dateTo" class="block mt-1 border rounded px-3 py-2 text-sm" />
      </label>
      <button @click="onPrint" :disabled="!statement"
              class="ml-auto inline-flex items-center gap-2 bg-gray-900 text-white px-4 py-2 rounded text-sm font-semibold disabled:opacity-40">
        <Printer :size="14" /> Print
      </button>
    </div>

    <!-- Error -->
    <div v-if="error" class="no-print mb-3 p-3 bg-red-50 border border-red-200 text-red-700 text-sm rounded">
      {{ error }}
      <button @click="refresh" class="ml-3 underline font-semibold">Retry</button>
    </div>

    <!-- Statement print area -->
    <div id="statement-print-area" class="relative bg-white p-8 border border-gray-200 text-gray-900">
      <!-- Page indicator -->
      <div class="absolute right-8 top-6 text-sm">Page 1 of 1</div>

      <!-- Header grid -->
      <div class="grid grid-cols-2 gap-12 mb-8">
        <!-- Left -->
        <div class="space-y-1 text-sm">
          <div class="grid grid-cols-[140px_1fr] gap-2">
            <div class="text-gray-700">Account Number:</div>
            <div>{{ statement?.account.account_no ?? '—' }}</div>
            <div class="text-gray-700">Statement Date:</div>
            <div>{{ fmtDate(statement?.period.statement_date) }}</div>
            <div class="text-gray-700">Period Covered:</div>
            <div>{{ fmtDate(statement?.period.date_from) }} to {{ fmtDate(statement?.period.date_to) }}</div>
          </div>
          <div class="pt-2 text-base font-semibold">{{ statement?.member.name }}</div>
          <div>{{ statement?.member.address }}</div>
          <div v-if="statement?.member.address_city">{{ statement.member.address_city }}</div>
        </div>
        <!-- Right -->
        <div class="text-sm">
          <div class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 font-mono">
            <div class="text-gray-700 font-sans">Opening Balance:</div>
            <div class="text-right">{{ formatCurrency(statement?.balances.opening ?? 0) }}</div>
            <div class="text-gray-700 font-sans">Total Credit Amount:</div>
            <div class="text-right">{{ formatCurrency(statement?.balances.total_credit ?? 0) }}</div>
            <div class="text-gray-700 font-sans">Total Debit Amount:</div>
            <div class="text-right">{{ formatCurrency(statement?.balances.total_debit ?? 0) }}</div>
            <div class="text-gray-700 font-sans">Closing Balance:</div>
            <div class="text-right font-semibold">{{ formatCurrency(statement?.balances.closing ?? 0) }}</div>
            <div class="text-gray-700 font-sans">Account Type:</div>
            <div class="text-right">{{ statement?.account.account_type ?? '—' }}</div>
            <div class="text-gray-700 font-sans">Number of Transactions:</div>
            <div class="text-right">{{ statement?.balances.count ?? 0 }}</div>
          </div>
        </div>
      </div>

      <div class="text-sm mb-4">&lt;{{ statement?.branch.name ?? 'Main branch' }}&gt;</div>

      <!-- Transactions table -->
      <table class="w-full text-sm">
        <thead class="bg-gray-50 border-y border-gray-200 text-xs uppercase tracking-wide text-gray-600">
          <tr>
            <th class="px-3 py-2 text-left w-32">Date</th>
            <th class="px-3 py-2 text-left">Description</th>
            <th class="px-3 py-2 text-right w-32">Credit</th>
            <th class="px-3 py-2 text-right w-32">Debit</th>
            <th class="px-3 py-2 text-right w-32">Balance</th>
          </tr>
        </thead>
        <tbody>
          <template v-if="loading">
            <tr v-for="i in 5" :key="i" class="border-b border-gray-100">
              <td colspan="5" class="px-3 py-3"><div class="h-3 bg-gray-100 rounded animate-pulse" /></td>
            </tr>
          </template>
          <template v-else-if="statement && statement.transactions.length > 0">
            <tr v-for="t in statement.transactions" :key="t.id" class="even:bg-gray-50">
              <td class="px-3 py-2">{{ fmtDate(t.date) }}</td>
              <td class="px-3 py-2">{{ t.is_reversal ? '(Reversal) ' : '' }}{{ t.description }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ t.credit ? formatCurrency(t.credit) : '' }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ t.debit ? formatCurrency(t.debit) : '' }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ formatCurrency(t.running_balance) }}</td>
            </tr>
            <tr class="bg-gray-50">
              <td colspan="5" class="px-3 py-2 text-center text-gray-500 text-xs">--- End of Transactions ---</td>
            </tr>
          </template>
          <tr v-else>
            <td colspan="5" class="px-3 py-8 text-center text-gray-500">No transactions in this period.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
@media print {
  :global(body) > :not(#statement-print-area-container),
  .no-print { display: none !important; }
  #statement-print-area { border: none; padding: 0; }
  table, tr, td, th { break-inside: avoid; }
  * { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
}
</style>
```

- [ ] **Step 2: Manually verify in the dev server.** Run `pnpm dev` (or `composer dev` on the backend in parallel). Navigate to `tenant/member/profile` for a member with at least one savings account. Confirm:
  - Account selector defaults to the first account; switching accounts re-fetches.
  - Date pickers default to last 90 days; changing them re-fetches.
  - Header shows the six right-column fields aligned; left column shows account/period/name/address.
  - Table renders with even-row shading; empty Credit cells stay blank for debit rows.
  - "End of Transactions" footer appears when there are rows; "No transactions in this period." appears when there aren't.
  - Click **Print** → browser print dialog shows only the statement (no app shell / no controls).

- [ ] **Step 3: Commit.**

```bash
git add src/tenant/modules/members/profile/statement.vue
git commit -m "feat(members): rebuild statement tab to bank-style per-account layout"
```

---

## Task 9: Frontend — Vitest spec for `statement.vue`

**Files:**
- Create: `src/tenant/modules/members/__tests__/statement.spec.ts`

- [ ] **Step 1: Write the spec.**

```ts
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Statement from '../profile/statement.vue';

const fixture = {
  account: { id: 1, account_no: 'SA-001', account_type: 'Voluntary', product_name: 'General' },
  member:  { id: 1, name: 'Maya Nyamu', member_number: 'MBRC-001', address: 'Kibada St', address_city: null },
  branch:  { name: 'Main branch' },
  period:  { date_from: '2026-02-01', date_to: '2026-02-28', statement_date: '2026-02-28' },
  balances:{ opening: 100, total_credit: 500, total_debit: 50, closing: 550, count: 2 },
  transactions: [
    { id: 1, date: '2026-02-05', description: 'Savings Deposit', credit: 500, debit: 0, running_balance: 600, is_reversal: false },
    { id: 2, date: '2026-02-10', description: 'Charge',          credit: 0, debit: 50, running_balance: 550, is_reversal: false },
  ],
  warnings: [],
};

vi.mock('@/tenant/apis/tenantClient', () => ({
  tenantClient: { get: vi.fn(() => Promise.resolve({ data: fixture })) },
}));

vi.mock('@/Global', async (orig) => {
  const actual = await orig() as any;
  return { ...actual, formatCurrency: (n: number) => n.toFixed(2), printElementId: vi.fn() };
});

describe('statement.vue', () => {
  it('renders header fields and reconciled totals from the API', async () => {
    const wrapper = mount(Statement, {
      props: { data: { savings_accounts: [{ id: 1, account_no: 'SA-001', account_type: 'Voluntary' }] } },
    });
    await new Promise((r) => setTimeout(r, 0));
    await nextTick();
    expect(wrapper.text()).toContain('SA-001');
    expect(wrapper.text()).toContain('Maya Nyamu');
    expect(wrapper.text()).toContain('Main branch');
    expect(wrapper.text()).toContain('550.00');         // closing
    expect(wrapper.text()).toContain('End of Transactions');
  });

  it('renders empty Credit cell for debit rows and vice versa', async () => {
    const wrapper = mount(Statement, {
      props: { data: { savings_accounts: [{ id: 1, account_no: 'SA-001', account_type: 'Voluntary' }] } },
    });
    await new Promise((r) => setTimeout(r, 0));
    await nextTick();
    const rows = wrapper.findAll('tbody tr');
    // first transaction row: credit=500, debit empty
    expect(rows[0].html()).toContain('500.00');
    expect(rows[0].findAll('td')[3].text()).toBe(''); // debit empty
  });
});
```

- [ ] **Step 2: Run — expect PASS.**

```bash
pnpm test:unit -- statement
```

- [ ] **Step 3: Commit.**

```bash
git add src/tenant/modules/members/__tests__/statement.spec.ts
git commit -m "test(members): statement.vue renders header, totals, and empty-cell rules"
```

---

## Task 10: Wrap-up — full backend + frontend suite

- [ ] **Step 1: Run full backend tests.**

```bash
cd mfuko-pro-backend-2026
composer test
```
Expected: all green; new Reports tests included.

- [ ] **Step 2: Run frontend lint + tests.**

```bash
cd mfuko-pro-frontend-2026
pnpm lint
pnpm test:unit
```
Expected: clean.

- [ ] **Step 3: Push the branch.**

```bash
git push -u origin member-statment-fix
```

- [ ] **Step 4: Open the PR** (use the link printed by the push, or `gh pr create`).

---

## Self-review

**Spec coverage:**
- §1 Routing → Task 6 ✔
- §2 Backend components → Tasks 1, 5, 6 ✔
- §3 Computation (opening / period / running / reconciliation) → Tasks 2, 3 ✔
- §4 Credit/Debit mapping → Task 3 (`classify()`), Task 4 covers warnings ✔
- §5 Response shape → Task 6 (`assertJsonStructure`) ✔
- §6 Frontend components → Tasks 7, 8 ✔
- §7 Layout → Task 8 ✔
- §8 States (loading/empty/error) → Task 8 (markup) + Task 9 (test) ✔
- §9 Print → Task 8 (`@media print` block + Print button) ✔
- §10 Testing → Tasks 2, 3, 4, 5, 6, 9 ✔
- §11 Out of scope → enforced by absence ✔

**Placeholder scan:** None — every step has actual code or exact commands.

**Type consistency:**
- Service interface method: `buildStatement(int, ?string, ?string): array` — used identically in Tasks 1, 2, 3, 5, 6.
- Response keys: `account / member / branch / period / balances / transactions / warnings` — same set in service (Task 3, 5), controller test (Task 6), composable type (Task 7), and component template (Task 8).
- Transaction row keys: `id / date / description / credit / debit / running_balance / is_reversal` — defined in Task 3, asserted in Tasks 3 & 9, typed in Task 7.

All consistent.
