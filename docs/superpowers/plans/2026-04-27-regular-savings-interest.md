# Regular Savings Interest Service — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `RegularSavingsInterestService` that calculates, posts, and records monthly interest for voluntary and mandatory savings accounts, plus an Artisan command to run it across all tenants.

**Architecture:** New service reusing `FixedDepositCalculator` (same formula), `SavingsJournalService::postInterest()` (same GL method), and `SavingsCoaResolver` (GL resolution). Artisan command follows the `CheckLoanArrearsCommand` pattern — iterates active tenants, switches DB, resolves service from container.

**Tech Stack:** Laravel 12, PHP 8.2, tenant DB, `FixedDepositCalculator`, `SavingsJournalService`, `SavingsCoaResolver`, `SavingsInterestPosting`.

**Prerequisite:** `SavingsCoaResolver` must exist (plan `2026-04-27-savings-coa-resolver.md` must be complete first).

---

## File Map

| File | Action |
|------|--------|
| `app/Tenant/Modules/Savings/Services/RegularSavingsInterestService.php` | Create |
| `app/Console/Commands/PostRegularSavingsInterestCommand.php` | Create — auto-discovered by Laravel 12, no registration needed |

---

## Task 1 — Create `RegularSavingsInterestService`

**Files:**
- Create: `app/Tenant/Modules/Savings/Services/RegularSavingsInterestService.php`

**Context:** The service must only process non-fixed savings accounts (`account_type` is `voluntary` or `mandatory`). Interest calculation uses the same formula as FD: `principal × (rate/100) × (days/365)`, delegated to the existing `FixedDepositCalculator::calculateInterest()`. The GL double entry is:
- DR `product->interest_expense_account_id` (fallback: GL 5110 via `SavingsCoaResolver::resolveByGlCode()`)
- CR savings liability GL (2111/2112 via `SavingsCoaResolver::resolveSavingsLiabilityAccount()`)

Interest is credited to the member's savings account balance (`account->increment('balance', $interest)`).
A `SavingsInterestPosting` record is created for every posting.
`last_interest_posted_at` and `next_interest_date` are updated after each successful posting.
The fields `next_interest_date` and `last_interest_posted_at` already exist on `savings_accounts` — added in migration `2026_04_19_000002_add_fd_fields_to_savings_accounts.php`.

- [ ] **Step 1: Create the service file**

```php
<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\SavingsCoaResolver;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsInterestPosting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegularSavingsInterestService
{
    public function __construct(
        protected FixedDepositCalculator $calculator,
        protected SavingsJournalService  $journal,
        protected SavingsCoaResolver     $coa,
    ) {}

    public function processAccount(SavingsAccount $account, int $actorId): void
    {
        if ($account->isFixed() || $account->status !== 'active') {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($account, $actorId) {
            $account = SavingsAccount::on('tenant')
                ->lockForUpdate()
                ->findOrFail($account->id);

            if (! $account->next_interest_date) {
                return;
            }

            if (Carbon::parse($account->next_interest_date)->gt(Carbon::today())) {
                return;
            }

            $account->loadMissing('savingsProduct');
            $product  = $account->savingsProduct;
            $from     = Carbon::parse($account->last_interest_posted_at ?? $account->created_at);
            $to       = Carbon::parse($account->next_interest_date);
            $rate     = (float) ($product?->interest_rate ?? $account->interest_rate ?? 0);
            $interest = $this->calculator->calculateInterest((float) $account->balance, $rate, $from, $to);

            if ($interest <= 0) {
                return;
            }

            $expenseAccountId = $product?->interest_expense_account_id;
            $expenseAccount   = $expenseAccountId
                ? ChartOfAccount::on('tenant')->find($expenseAccountId)
                : $this->coa->resolveByGlCode('5110');

            $creditAccount = $this->coa->resolveSavingsLiabilityAccount($account);

            $je = $this->journal->postInterest(
                $account,
                $expenseAccount,
                $creditAccount,
                $interest,
                $from->toDateString(),
                $to->toDateString(),
                $actorId,
            );

            $account->increment('balance', $interest);

            SavingsInterestPosting::on('tenant')->create([
                'savings_account_id' => $account->id,
                'period_start'       => $from->toDateString(),
                'period_end'         => $to->toDateString(),
                'principal'          => $account->balance,
                'rate'               => $rate,
                'interest_amount'    => $interest,
                'payout_type'        => 'periodic_payout',
                'journal_entry_id'   => $je?->id,
                'posted_by'          => $actorId,
                'created_at'         => now(),
            ]);

            $frequency = $product?->interest_posting_frequency ?? 'monthly';
            $account->update([
                'last_interest_posted_at' => now(),
                'next_interest_date'      => $this->calculator->nextInterestDate($to, $frequency),
            ]);
        });
    }

    public function runMonthEndSweep(int $actorId): array
    {
        $summary = ['posted' => 0, 'skipped' => 0, 'errors' => []];

        SavingsAccount::on('tenant')
            ->whereIn('account_type', ['voluntary', 'mandatory'])
            ->where('status', 'active')
            ->whereNotNull('next_interest_date')
            ->whereDate('next_interest_date', '<=', today())
            ->each(function (SavingsAccount $account) use ($actorId, &$summary) {
                try {
                    $this->processAccount($account, $actorId);
                    $summary['posted']++;
                } catch (\Throwable $e) {
                    $summary['skipped']++;
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

- [ ] **Step 2: Lint**

```bash
composer test:lint
```

Expected: `RegularSavingsInterestService.php` not in failure list.

- [ ] **Step 3: Commit**

```bash
git add app/Tenant/Modules/Savings/Services/RegularSavingsInterestService.php
git commit -m "feat: add RegularSavingsInterestService for voluntary/mandatory savings interest (#10)"
```

---

## Task 2 — Create `PostRegularSavingsInterestCommand`

**Files:**
- Create: `app/Console/Commands/PostRegularSavingsInterestCommand.php`

**Context:** Laravel 12 auto-discovers all classes in `app/Console/Commands/` — no Kernel.php or console.php registration needed. The command iterates all active tenants, switches the DB connection using `DatabaseSwitcher`, resolves `RegularSavingsInterestService` fresh from the container (so it gets the correct tenant DB connection), and calls `runMonthEndSweep(0)` (0 = system actor, avoids staff FK constraint). This mirrors `CheckLoanArrearsCommand` exactly.

- [ ] **Step 1: Create the command file**

```php
<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Savings\Services\RegularSavingsInterestService;
use Illuminate\Console\Command;

class PostRegularSavingsInterestCommand extends Command
{
    protected $signature = 'savings:post-regular-interest
                            {--tenant= : Run for a specific tenant subdomain only}';

    protected $description = 'Post monthly interest for voluntary and mandatory savings accounts across all tenants.';

    public function __construct(protected DatabaseSwitcher $switcher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantSubdomain = $this->option('tenant');

        $query = Tenant::where('status', 'active');
        if ($tenantSubdomain) {
            $query->where('subdomain', $tenantSubdomain);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');

            return self::SUCCESS;
        }

        $totalPosted  = 0;
        $totalSkipped = 0;

        foreach ($tenants as $tenant) {
            try {
                $this->switcher->switch($tenant);

                /** @var RegularSavingsInterestService $service */
                $service = app(RegularSavingsInterestService::class);
                $result  = $service->runMonthEndSweep(0);

                $totalPosted  += $result['posted'];
                $totalSkipped += $result['skipped'];

                $this->line("  [{$tenant->subdomain}] posted={$result['posted']} skipped={$result['skipped']}");
            } catch (\Throwable $e) {
                $this->error("  [{$tenant->subdomain}] failed: {$e->getMessage()}");
            }
        }

        $this->info("Done. posted={$totalPosted} skipped={$totalSkipped}");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Verify the command is available**

```bash
php artisan list | grep savings
```

Expected output includes:
```
savings:post-regular-interest  Post monthly interest for voluntary and mandatory savings accounts across all tenants.
```

- [ ] **Step 3: Lint**

```bash
composer test:lint
```

Expected: `PostRegularSavingsInterestCommand.php` not in failure list.

- [ ] **Step 4: Commit**

```bash
git add app/Console/Commands/PostRegularSavingsInterestCommand.php
git commit -m "feat: add savings:post-regular-interest artisan command (#10)"
```
