<?php

namespace Tests\Feature\Savings;

use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsInterestPosting;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Services\FixedDepositCalculator;
use App\Tenant\Modules\Savings\Services\FixedDepositInterestService;
use Carbon\Carbon;
use Tests\TenantTestCase;

class FixedDepositInterestTest extends TenantTestCase
{
    // ── Unit: calculator ──────────────────────────────────────────────────────

    public function test_calculator_computes_simple_interest_correctly(): void
    {
        $calc = new FixedDepositCalculator;
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
            'account_no' => 'FD-000001', 'code' => 'FD-000001', 'account_type' => 'fixed',
            'balance' => 100_000, 'interest_rate' => 0.12, 'status' => 'active',
            'tenor_months' => 6,
            'maturity_date' => now()->subDay()->toDateString(),
            // last_interest_posted_at sets the "from" date for interest calc
            'last_interest_posted_at' => now()->subMonths(6),
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
            'account_no' => 'FD-000002', 'code' => 'FD-000002', 'account_type' => 'fixed',
            'balance' => 50_000, 'interest_rate' => 0.12, 'status' => 'active',
            'tenor_months' => 6,
            'maturity_date' => now()->subDay()->toDateString(),
            'last_interest_posted_at' => now()->subMonths(6),
        ]);

        $service = app(FixedDepositInterestService::class);
        $service->processAccount($account, 1);
        // second call — account is now 'matured', processAccount returns early
        $service->processAccount($account->fresh(), 1);

        $this->assertCount(
            1,
            SavingsInterestPosting::where('savings_account_id', $account->id)->get()
        );
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
                'account_no' => $no, 'code' => $no, 'account_type' => 'fixed',
                'balance' => 100_000, 'interest_rate' => 0.12, 'status' => 'active',
                'tenor_months' => 12, 'maturity_date' => now()->addYear()->toDateString(),
                'next_interest_date' => now()->subDay()->toDateString(),
            ]);
        }

        $service = app(FixedDepositInterestService::class);
        $result = $service->runMonthEndSweep(1);

        $this->assertEquals(2, $result['posted']);
        $this->assertCount(0, $result['errors']);
    }
}
