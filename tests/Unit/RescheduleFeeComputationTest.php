<?php

namespace Tests\Unit;

use App\Tenant\Modules\Accounting\Services\LoanAccountingService;
use App\Tenant\Modules\Loans\Contracts\ScheduleGeneratorServiceInterface;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Services\LoanRescheduleService;
use App\Tenant\Modules\Settings\Models\LoanSetting;
use App\Tenant\Modules\Settings\Services\HolidayService;
use Mockery;
use PHPUnit\Framework\TestCase;

class RescheduleFeeComputationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    private function makeService(): LoanRescheduleService
    {
        $scheduleGen = Mockery::mock(ScheduleGeneratorServiceInterface::class);
        $holidaySvc = Mockery::mock(HolidayService::class);
        $loanAcctSvc = Mockery::mock(LoanAccountingService::class);

        return new LoanRescheduleService($scheduleGen, $holidaySvc, $loanAcctSvc);
    }

    private function makeLoan(int $productId = 1, float $disbursed = 100000): Loan
    {
        $loan = Mockery::mock(Loan::class)->makePartial();
        $loan->loan_product_id = $productId;
        $loan->net_disbursed_amount = $disbursed;

        return $loan;
    }

    private function makeSettings(array $overrides = []): LoanSetting
    {
        $s = new LoanSetting;
        // safe defaults (all disabled)
        $s->reschedule_fee_enabled = false;
        $s->reschedule_fee_type = 'flat';
        $s->reschedule_fee_amount = 0;
        $s->reschedule_fee_basis = null;
        $s->reschedule_fee_collection = 'cash';
        $s->reschedule_product_change_fee_enabled = false;
        $s->reschedule_product_change_fee_type = 'flat';
        $s->reschedule_product_change_fee_amount = 0;
        $s->reschedule_product_change_fee_basis = null;
        $s->reschedule_product_change_fee_collection = 'cash';
        $s->reschedule_same_product_fee_enabled = false;
        $s->reschedule_same_product_fee_type = 'flat';
        $s->reschedule_same_product_fee_amount = 0;
        $s->reschedule_same_product_fee_basis = null;
        $s->reschedule_same_product_fee_collection = 'cash';
        $s->reschedule_other_charges_enabled = false;
        $s->reschedule_other_charges_type = 'flat';
        $s->reschedule_other_charges_amount = 0;
        $s->reschedule_other_charges_basis = null;
        $s->reschedule_other_charges_collection = 'cash';
        foreach ($overrides as $k => $v) {
            $s->$k = $v;
        }

        return $s;
    }

    public function test_no_fees_when_all_disabled(): void
    {
        $svc = $this->makeService();
        $loan = $this->makeLoan(productId: 1);
        $settings = $this->makeSettings();
        $snapshot = ['outstanding_balance' => 50000.0];
        $params = [];

        $result = $svc->computeRescheduleFees($loan, $settings, $params, $snapshot, 50000.0);

        $this->assertSame(0.0, $result['capitalize_total']);
        $this->assertEmpty($result['fees_detail']);
    }

    public function test_flat_reschedule_fee_always_applied_when_enabled(): void
    {
        $svc = $this->makeService();
        $loan = $this->makeLoan(productId: 1);
        $settings = $this->makeSettings([
            'reschedule_fee_enabled' => true,
            'reschedule_fee_type' => 'flat',
            'reschedule_fee_amount' => 500.0,
            'reschedule_fee_collection' => 'cash',
        ]);
        $snapshot = ['outstanding_balance' => 50000.0];

        $result = $svc->computeRescheduleFees($loan, $settings, [], $snapshot, 50000.0);

        $this->assertCount(1, $result['fees_detail']);
        $this->assertSame(500.0, $result['fees_detail'][0]['amount']);
        $this->assertSame('cash', $result['fees_detail'][0]['collection']);
        $this->assertSame(0.0, $result['capitalize_total']);
    }

    public function test_percentage_fee_against_outstanding_balance(): void
    {
        $svc = $this->makeService();
        $loan = $this->makeLoan(productId: 1, disbursed: 100000.0);
        $settings = $this->makeSettings([
            'reschedule_fee_enabled' => true,
            'reschedule_fee_type' => 'percentage',
            'reschedule_fee_amount' => 2.0,
            'reschedule_fee_basis' => 'outstanding_balance',
            'reschedule_fee_collection' => 'savings',
        ]);
        $snapshot = ['outstanding_balance' => 60000.0];

        $result = $svc->computeRescheduleFees($loan, $settings, [], $snapshot, 55000.0);

        // 2% of 60000 = 1200
        $this->assertEqualsWithDelta(1200.0, $result['fees_detail'][0]['amount'], 0.01);
    }

    public function test_percentage_fee_against_new_principal(): void
    {
        $svc = $this->makeService();
        $loan = $this->makeLoan(productId: 1);
        $settings = $this->makeSettings([
            'reschedule_fee_enabled' => true,
            'reschedule_fee_type' => 'percentage',
            'reschedule_fee_amount' => 1.0,
            'reschedule_fee_basis' => 'new_principal',
            'reschedule_fee_collection' => 'cash',
        ]);
        $snapshot = ['outstanding_balance' => 60000.0];

        $result = $svc->computeRescheduleFees($loan, $settings, [], $snapshot, 55000.0);

        // 1% of 55000 = 550
        $this->assertEqualsWithDelta(550.0, $result['fees_detail'][0]['amount'], 0.01);
    }

    public function test_percentage_fee_against_original_disbursed(): void
    {
        $svc = $this->makeService();
        $loan = $this->makeLoan(productId: 1, disbursed: 80000.0);
        $settings = $this->makeSettings([
            'reschedule_fee_enabled' => true,
            'reschedule_fee_type' => 'percentage',
            'reschedule_fee_amount' => 0.5,
            'reschedule_fee_basis' => 'original_disbursed',
            'reschedule_fee_collection' => 'cash',
        ]);
        $snapshot = ['outstanding_balance' => 60000.0];

        $result = $svc->computeRescheduleFees($loan, $settings, [], $snapshot, 55000.0);

        // 0.5% of 80000 = 400
        $this->assertEqualsWithDelta(400.0, $result['fees_detail'][0]['amount'], 0.01);
    }

    public function test_capitalize_collection_adds_to_capitalize_total(): void
    {
        $svc = $this->makeService();
        $loan = $this->makeLoan(productId: 1);
        $settings = $this->makeSettings([
            'reschedule_fee_enabled' => true,
            'reschedule_fee_type' => 'flat',
            'reschedule_fee_amount' => 300.0,
            'reschedule_fee_collection' => 'capitalize',
        ]);
        $snapshot = ['outstanding_balance' => 50000.0];

        $result = $svc->computeRescheduleFees($loan, $settings, [], $snapshot, 50000.0);

        $this->assertSame(300.0, $result['capitalize_total']);
    }

    public function test_product_change_fee_fires_only_when_product_changes(): void
    {
        $svc = $this->makeService();
        $loan = $this->makeLoan(productId: 1);

        $settings = $this->makeSettings([
            'reschedule_product_change_fee_enabled' => true,
            'reschedule_product_change_fee_type' => 'flat',
            'reschedule_product_change_fee_amount' => 1000.0,
            'reschedule_product_change_fee_collection' => 'cash',
        ]);
        $snapshot = ['outstanding_balance' => 50000.0];

        // Same product — fee should NOT fire
        $result = $svc->computeRescheduleFees($loan, $settings, ['new_loan_product_id' => 1], $snapshot, 50000.0);
        $this->assertEmpty($result['fees_detail']);

        // Different product — fee SHOULD fire
        $result = $svc->computeRescheduleFees($loan, $settings, ['new_loan_product_id' => 2], $snapshot, 50000.0);
        $this->assertCount(1, $result['fees_detail']);
        $this->assertSame(1000.0, $result['fees_detail'][0]['amount']);
    }

    public function test_same_product_fee_fires_only_when_product_unchanged(): void
    {
        $svc = $this->makeService();
        $loan = $this->makeLoan(productId: 1);

        $settings = $this->makeSettings([
            'reschedule_same_product_fee_enabled' => true,
            'reschedule_same_product_fee_type' => 'flat',
            'reschedule_same_product_fee_amount' => 200.0,
            'reschedule_same_product_fee_collection' => 'savings',
        ]);
        $snapshot = ['outstanding_balance' => 50000.0];

        // Same product (or no new product specified) — fee fires
        $result = $svc->computeRescheduleFees($loan, $settings, [], $snapshot, 50000.0);
        $this->assertCount(1, $result['fees_detail']);

        // Different product — fee does NOT fire
        $result = $svc->computeRescheduleFees($loan, $settings, ['new_loan_product_id' => 2], $snapshot, 50000.0);
        $this->assertEmpty($result['fees_detail']);
    }

    public function test_other_charges_fire_only_when_admin_flag_set(): void
    {
        $svc = $this->makeService();
        $loan = $this->makeLoan();

        $settings = $this->makeSettings([
            'reschedule_other_charges_enabled' => true,
            'reschedule_other_charges_type' => 'flat',
            'reschedule_other_charges_amount' => 150.0,
            'reschedule_other_charges_collection' => 'cash',
        ]);
        $snapshot = ['outstanding_balance' => 50000.0];

        // No admin flag — does NOT fire
        $result = $svc->computeRescheduleFees($loan, $settings, [], $snapshot, 50000.0);
        $this->assertEmpty($result['fees_detail']);

        // Admin flag present — fires
        $result = $svc->computeRescheduleFees($loan, $settings, ['apply_other_charges' => true], $snapshot, 50000.0);
        $this->assertCount(1, $result['fees_detail']);
        $this->assertSame(150.0, $result['fees_detail'][0]['amount']);
    }
}
