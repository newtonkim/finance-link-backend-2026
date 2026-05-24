<?php

namespace Tests\Unit\Loans;

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Modules\Loans\Models\LoanStatusHistory;
use App\Tenant\Modules\Loans\Models\LoanTransaction;
use App\Tenant\Modules\Loans\Services\LoanActivityService;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TenantTestCase;

/**
 * Feature tests for LoanActivityService::getActivity().
 *
 * These tests run against the real tenant test DB (via TenantTestCase).
 * They are placed under tests/Unit/Loans/ per the directory structure
 * requested, but they extend TenantTestCase so they can persist Eloquent
 * models and exercise the real query paths inside the service.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class LoanActivityServiceTest extends TenantTestCase
{
    private LoanActivityService $service;

    private Staff $staff;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LoanActivityService;

        $this->staff = Staff::create([
            'name' => 'Activity Tester',
            'email' => 'activitytester@test.com',
            'password' => Hash::make('password'),
            'role' => 'Admin',
            'is_tenant_admin' => true,
        ]);

        $this->member = Member::create([
            'name' => 'Test Member',
            'email' => 'loan-activity-'.uniqid().'@test.local',
            'member_number' => 'MBR-ACT-'.uniqid(),
            'code' => 'MBR-ACT-'.uniqid(),
            'status' => 'active',
            'branch_id' => 1,
            'password' => Hash::make('password'),
        ]);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    /**
     * Create a minimal disbursed Loan row.
     */
    private function makeLoan(array $overrides = []): Loan
    {
        return Loan::create(array_merge([
            'loan_no' => 'LN-TEST-'.uniqid(),
            'member_id' => $this->member->id,
            'loan_product_id' => null,
            'principal' => 50000.00,
            'net_disbursed_amount' => 48000.00,
            'interest_rate' => 12.00,
            'term_months' => 12,
            'disbursed_at' => \Illuminate\Support\Carbon::now()->subMonths(3)->toDateString(),
            'disbursement_method' => 'bank_transfer',
            'status' => 'disbursed',
            'outstanding_balance' => 48000.00,
            'branch_id' => 1,
        ], $overrides));
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * Case 1: A loan with disbursed_at set produces a 'disbursed' event
     * with title 'Loan Disbursed'.
     */
    public function test_disbursed_event_is_present_when_loan_has_disbursed_at(): void
    {
        $loan = $this->makeLoan(['disbursed_at' => \Illuminate\Support\Carbon::now()->subMonths(3)->toDateString()]);

        $activity = $this->service->getActivity($loan);

        $disbursedEvents = $activity->where('type', 'disbursed')->values();

        $this->assertCount(1, $disbursedEvents);
        $this->assertEquals('Loan Disbursed', $disbursedEvents->first()['title']);
    }

    /**
     * Case 2: A loan with two non-reversed repayment transactions returns
     * exactly two events with type 'repayment'.
     */
    public function test_repayment_events_match_non_reversed_transactions(): void
    {
        $loan = $this->makeLoan();

        // Create two valid repayments
        LoanTransaction::create([
            'loan_id' => $loan->id,
            'member_id' => $this->member->id,
            'amount_paid' => 5000.00,
            'payment_date' => \Illuminate\Support\Carbon::now()->subMonths(2)->toDateString(),
            'payment_method' => 'cash',
            'reversal_flag' => false,
        ]);
        LoanTransaction::create([
            'loan_id' => $loan->id,
            'member_id' => $this->member->id,
            'amount_paid' => 5000.00,
            'payment_date' => \Illuminate\Support\Carbon::now()->subMonth()->toDateString(),
            'payment_method' => 'mobile_money',
            'reversal_flag' => false,
        ]);

        // Create a reversed repayment that should be excluded
        LoanTransaction::create([
            'loan_id' => $loan->id,
            'member_id' => $this->member->id,
            'amount_paid' => 5000.00,
            'payment_date' => \Illuminate\Support\Carbon::now()->subWeeks(2)->toDateString(),
            'payment_method' => 'cash',
            'reversal_flag' => true,
        ]);

        $activity = $this->service->getActivity($loan);

        $repaymentEvents = $activity->where('type', 'repayment')->values();

        $this->assertCount(2, $repaymentEvents);
    }

    /**
     * Case 3: A loan schedule row with penalty_due > 0 produces a
     * 'penalty_assessed' event with the correct formatted amount.
     */
    public function test_penalty_events_appear_for_schedule_rows_with_penalty_due(): void
    {
        $loan = $this->makeLoan();

        LoanSchedule::create([
            'loan_id' => $loan->id,
            'installment_no' => 1,
            'due_date' => \Illuminate\Support\Carbon::now()->subMonths(2)->toDateString(),
            'principal_due' => 4000.00,
            'interest_due' => 500.00,
            'charges_due' => 0.00,
            'penalty_due' => 250.00,
            'total_due' => 4500.00,
            'status' => 'arrears',
        ]);

        $activity = $this->service->getActivity($loan);

        $penaltyEvents = $activity->where('type', 'penalty_assessed')->values();

        $this->assertCount(1, $penaltyEvents);
        $event = $penaltyEvents->first();
        $this->assertEquals('Penalty Assessed', $event['title']);
        $this->assertNotNull($event['amount']);
        $this->assertStringContainsString('250', $event['amount']);
    }

    /**
     * Case 4: A LoanStatusHistory record produces an event with
     * type 'status_change'.
     */
    public function test_status_change_event_is_produced_from_loan_status_history(): void
    {
        $loan = $this->makeLoan();

        LoanStatusHistory::create([
            'loan_id' => $loan->id,
            'from_status' => 'approved',
            'to_status' => 'disbursed',
            'changed_by' => $this->staff->id,
            'changed_at' => \Illuminate\Support\Carbon::now()->subMonths(3),
            'notes' => 'Disbursed to member.',
        ]);

        $activity = $this->service->getActivity($loan);

        $statusChangeEvents = $activity->where('type', 'status_change')->values();

        $this->assertCount(1, $statusChangeEvents);
        $this->assertEquals('status_change', $statusChangeEvents->first()['type']);
    }

    /**
     * Case 5: All events returned by getActivity() are sorted in
     * ascending chronological order (oldest timestamp first).
     */
    public function test_events_are_sorted_by_timestamp_ascending(): void
    {
        $loan = $this->makeLoan([
            'disbursed_at' => \Illuminate\Support\Carbon::now()->subMonths(3)->toDateString(),
        ]);

        // Status change happened after disbursement
        LoanStatusHistory::create([
            'loan_id' => $loan->id,
            'from_status' => 'approved',
            'to_status' => 'disbursed',
            'changed_by' => $this->staff->id,
            'changed_at' => \Illuminate\Support\Carbon::now()->subMonths(3)->addDay(),
        ]);

        // Repayment happened more recently
        LoanTransaction::create([
            'loan_id' => $loan->id,
            'member_id' => $this->member->id,
            'amount_paid' => 5000.00,
            'payment_date' => \Illuminate\Support\Carbon::now()->subMonth()->toDateString(),
            'payment_method' => 'cash',
            'reversal_flag' => false,
        ]);

        // Penalty on a schedule row with a due_date in the past
        LoanSchedule::create([
            'loan_id' => $loan->id,
            'installment_no' => 1,
            'due_date' => \Illuminate\Support\Carbon::now()->subWeeks(6)->toDateString(),
            'principal_due' => 4000.00,
            'interest_due' => 500.00,
            'charges_due' => 0.00,
            'penalty_due' => 200.00,
            'total_due' => 4500.00,
            'status' => 'arrears',
        ]);

        $activity = $this->service->getActivity($loan);

        $this->assertGreaterThan(1, $activity->count(), 'Expected multiple events for ordering test');

        $timestamps = $activity->pluck('timestamp');

        for ($i = 0; $i < $timestamps->count() - 1; $i++) {
            $current = $timestamps[$i];
            $next = $timestamps[$i + 1];

            // Each timestamp should be <= the next (ascending order).
            $this->assertLessThanOrEqual(
                $next,
                $current,
                "Events are not sorted ascending: event[{$i}] timestamp ({$current}) > event[".($i + 1)."] timestamp ({$next})"
            );
        }
    }
}
