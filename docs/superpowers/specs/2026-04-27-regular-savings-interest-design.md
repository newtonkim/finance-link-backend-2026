# Regular Savings Interest Service — Design Spec

**Issue:** #10 — Regular savings (voluntary + mandatory) have no interest accrual or GL posting service.

**Goal:** Add `RegularSavingsInterestService` that calculates, posts, and records interest for voluntary and mandatory savings accounts on a configurable schedule, mirroring the pattern established by `FixedDepositInterestService`.

**Architecture:** New service + new Artisan command. Reuses `FixedDepositCalculator` (same formula), `SavingsJournalService::postInterest()` (same GL method), and `SavingsCoaResolver` (GL resolution). Records in `savings_interest_postings`. No new migrations — `next_interest_date` and `last_interest_posted_at` already exist on `savings_accounts`.

**Tech Stack:** Laravel 12, PHP 8.2, tenant DB, `FixedDepositCalculator`, `SavingsJournalService`, `SavingsCoaResolver`.

**Prerequisite:** `SavingsCoaResolver` must exist (see `2026-04-27-savings-coa-resolver-design.md`).

---

## Interest Calculation

Same formula as FD:

```
interest = principal × (annual_rate / 100) × (days / 365)
```

- `principal` = `account->balance` at time of posting
- `annual_rate` = `product->interest_rate ?? account->interest_rate`
- `days` = days between `last_interest_posted_at` (or account `created_at`) and `next_interest_date`

Computed by `FixedDepositCalculator::calculateInterest()` — no new calculation code.

---

## Double Entry

```
DR  Interest Expense on Savings  (product->interest_expense_account_id, fallback GL 5110)
CR  Member Savings Liability      (GL 2111 mandatory / GL 2112 voluntary — via SavingsCoaResolver)
```

Interest is credited directly to the member's savings liability account (increasing the member's balance), not to a separate payable account. The balance increment (`account->increment('balance', $interest)`) mirrors how compound FDs handle it.

---

## Service — `RegularSavingsInterestService`

**File:** `app/Tenant/Modules/Savings/Services/RegularSavingsInterestService.php`

```php
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
                ->lockForUpdate()->findOrFail($account->id);

            if (! $account->next_interest_date) {
                return;
            }

            $today = Carbon::today();
            if (Carbon::parse($account->next_interest_date)->gt($today)) {
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
                $account, $expenseAccount, $creditAccount,
                $interest, $from->toDateString(), $to->toDateString(), $actorId,
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

---

## Artisan Command

**File:** `app/Console/Commands/PostRegularSavingsInterestCommand.php`

Follows the exact pattern of `CheckLoanArrearsCommand`:

```php
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
                $result  = $service->runMonthEndSweep(0); // 0 = system actor

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

Register in `app/Console/Kernel.php` under `$commands`.

---

## Error Handling

- `processAccount()` is wrapped in a DB transaction; any exception rolls back and is caught by `runMonthEndSweep()`.
- `SavingsJournalService::postInterest()` is itself wrapped in `safe()` — a missing COA logs a warning and returns `null` JE, but the interest is still posted to the balance and `SavingsInterestPosting` is still recorded.
- Missing `interest_expense_account_id` falls back to GL 5110 via `SavingsCoaResolver::resolveByGlCode()`.

---

## Files Changed

| File | Action |
|------|--------|
| `app/Tenant/Modules/Savings/Services/RegularSavingsInterestService.php` | Create |
| `app/Console/Commands/PostRegularSavingsInterestCommand.php` | Create |
| `app/Console/Kernel.php` | Register command in `$commands` |
