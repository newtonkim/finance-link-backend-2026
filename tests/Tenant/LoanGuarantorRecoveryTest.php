<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Modules\Loans\Services\GuarantorRecoveryService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/guarantor_helpers.php';

/**
 * Recovering a defaulted loan: the borrower's free savings first, then the
 * guarantors' in proportion to what they guaranteed, proposed by one staff member
 * and approved by another, leaving the borrower a recovery loan owed to the
 * guarantors. These run against a real chart of accounts, so balances and journal
 * entries are checked, not just statuses.
 */
beforeEach(function () {
    Bus::fake();

    $this->maker = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->checker = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->maker, 'sanctum');

    seedGuarantorSettings();
    setGuarantorSetting('sacco-guarantor-recovery-after-days', 90);

    $coa = fn (string $code, string $name, string $type, string $balance) => ChartOfAccount::create([
        'gl_code' => $code, 'name' => $name, 'account_type' => $type, 'account_subtype' => strtolower($type),
        'normal_balance' => $balance, 'level' => 4, 'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);
    $this->cash = $coa('11101', 'Petty Cash', 'ASSET', 'DR');
    $coa('11102', 'Bank', 'ASSET', 'DR');
    $this->savingsGl = $coa('21102', 'Voluntary Savings', 'LIABILITY', 'CR');
    $coa('21101', 'Mandatory Savings', 'LIABILITY', 'CR');
    $coa('21103', 'Fixed Deposits', 'LIABILITY', 'CR');
    $this->portfolio = $coa('11310', 'Loan Portfolio', 'ASSET', 'DR');
    $this->interestIncome = $coa('41100', 'Interest Income', 'INCOME', 'CR');
});

function recoveries(): GuarantorRecoveryService
{
    return app(GuarantorRecoveryService::class);
}

/**
 * A loan 100 days overdue with 1000 outstanding (all principal), guaranteed by the
 * given members' pledges. Returns the loan and the pledges.
 *
 * @param  array<int, array{0: Member|SavingsGroup, 1: float}>  $guarantors
 */
function defaultedLoan(Member $borrower, array $guarantors, float $outstanding = 1000): array
{
    $test = test();
    $application = guarantorApplication(
        ['member_id' => $borrower->id, 'requested_amount' => $outstanding],
        [
            'loan_portfolio_account_id' => $test->portfolio->id,
            'interest_income_account_id' => $test->interestIncome->id,
            'interest_receivable_account_id' => $test->interestIncome->id,
            'disbursement_account_id' => $test->cash->id,
            'interest_method' => 'reducing_balance',
        ]
    );

    $pledges = array_map(fn ($g) => pledge($application, $g[0], $g[1]), $guarantors);

    $loan = Loan::create([
        'loan_no' => 'LN-'.uniqid(),
        'loan_application_id' => $application->id,
        'member_id' => $borrower->id,
        'loan_product_id' => $application->loan_product_id,
        'principal' => $outstanding,
        'outstanding_balance' => $outstanding,
        'status' => 'arrears',
        'interest_rate' => 0,
        'term_months' => 2,
        'disbursed_at' => now()->subDays(130),
        'schedule_date' => now()->subDays(130),
        'branch_id' => 1,
    ]);
    foreach ([100, 70] as $i => $daysAgo) {
        LoanSchedule::create([
            'loan_id' => $loan->id, 'installment_no' => $i + 1,
            'due_date' => now()->subDays($daysAgo)->toDateString(),
            'principal_due' => $outstanding / 2, 'interest_due' => 0, 'charges_due' => 0, 'penalty_due' => 0,
            'total_due' => $outstanding / 2, 'principal_paid' => 0, 'interest_paid' => 0, 'charges_paid' => 0,
            'penalty_paid' => 0, 'status' => 'pending',
        ]);
    }
    $application->update(['status' => LoanApplication::STATUS_DISBURSED, 'disbursed_loan_id' => $loan->id]);
    app(LoanGuarantorServiceInterface::class)->lockForLoan($application->fresh(), $loan);

    return [$loan->fresh(), array_map(fn ($p) => $p->fresh(), $pledges)];
}

function balanceOf(Member $member): float
{
    return (float) SavingsAccount::where('member_id', $member->id)->sum('balance');
}

it('takes the borrower\'s free savings first, then shares the rest by what each guaranteed', function () {
    $borrower = memberWithSavings(200);
    $a = memberWithSavings(5000);
    $b = memberWithSavings(5000);
    [$loan] = defaultedLoan($borrower, [[$a, 600], [$b, 200]]);

    $plan = recoveries()->plan($loan);

    expect($plan['eligible'])->toBeTrue()
        ->and($plan['borrower_amount'])->toBe(200.0)
        ->and($plan['guarantor_amount'])->toBe(800.0)
        ->and($plan['shortfall'])->toBe(0.0);

    $byMember = collect($plan['lines'])->groupBy('member_id')->map->sum('amount');
    // 800 split 600:200.
    expect($byMember[$a->id])->toBe(600.0)
        ->and($byMember[$b->id])->toBe(200.0);
});

it('caps a guarantor at their free savings and passes the rest to the others', function () {
    $borrower = memberWithSavings(0);
    $poor = memberWithSavings(100);
    $rich = memberWithSavings(5000);
    setGuarantorSetting('sacco-guarantor-hold-savings', 0); // let the pledge exceed savings for this case
    [$loan] = defaultedLoan($borrower, [[$poor, 100], [$rich, 900]]);
    // The poorer guarantor has since spent most of their savings.
    SavingsAccount::where('member_id', $poor->id)->update(['balance' => 40]);
    setGuarantorSetting('sacco-guarantor-hold-savings', 1);

    $plan = recoveries()->plan($loan);
    $byMember = collect($plan['lines'])->groupBy('member_id')->map->sum('amount');

    expect($byMember[$poor->id])->toBe(40.0)
        ->and($byMember[$rich->id])->toBe(900.0)
        ->and($plan['shortfall'])->toBe(60.0);
});

it('leaves out group guarantors, explaining why', function () {
    $borrower = memberWithSavings(0);
    $member = memberWithSavings(5000);
    $group = SavingsGroup::factory()->create();
    DB::table('group_savings_accounts')->insert([
        'savings_group_id' => $group->id, 'savings_product_id' => SavingsAccount::first()->savings_product_id,
        'balance' => 5000, 'created_at' => now(), 'updated_at' => now(),
    ]);
    [$loan] = defaultedLoan($borrower, [[$member, 500], [$group, 500]]);

    $plan = recoveries()->plan($loan);

    expect($plan['guarantor_amount'])->toBe(500.0)
        ->and($plan['unsupported_guarantors'])->toHaveCount(1)
        ->and($plan['unsupported_guarantors'][0]['reason'])->toContain('not in the general ledger');
});

it('does not allow recovery before the loan is overdue long enough', function () {
    [$loan] = defaultedLoan(memberWithSavings(0), [[memberWithSavings(5000), 1000]]);
    setGuarantorSetting('sacco-guarantor-recovery-after-days', 120);

    $plan = recoveries()->plan($loan);

    expect($plan['eligible'])->toBeFalse()
        ->and($plan['reasons'][0])->toContain('120 days overdue');
});

it('does not recover a written-off loan', function () {
    [$loan] = defaultedLoan(memberWithSavings(0), [[memberWithSavings(5000), 1000]]);
    $loan->update(['status' => 'written_off']);

    expect(recoveries()->plan($loan->fresh())['reasons'][0])->toContain('written off');
});

it('needs a second person to approve a recovery', function () {
    [$loan] = defaultedLoan(memberWithSavings(0), [[memberWithSavings(5000), 1000]]);
    $recovery = recoveries()->propose($loan, null, 'Borrower unreachable', $this->maker->id);

    expect($recovery->status)->toBe('pending_approval');
    expect(validationErrors(fn () => recoveries()->approve($recovery, $this->maker->id))['recovery'][0])
        ->toContain('someone other than');
});

it('refuses a second proposal while one is waiting', function () {
    [$loan] = defaultedLoan(memberWithSavings(0), [[memberWithSavings(5000), 1000]]);
    recoveries()->propose($loan, null, null, $this->maker->id);

    expect(fn () => recoveries()->propose($loan, null, null, $this->maker->id))->toThrow(ValidationException::class);
});

it('recovers the loan from savings when approved, with balanced journal entries', function () {
    $borrower = memberWithSavings(200);
    $a = memberWithSavings(5000);
    $b = memberWithSavings(5000);
    [$loan, [$pledgeA, $pledgeB]] = defaultedLoan($borrower, [[$a, 600], [$b, 200]]);
    $journalsBefore = JournalEntry::count();

    $recovery = recoveries()->propose($loan, null, null, $this->maker->id);
    recoveries()->approve($recovery, $this->checker->id);

    // Savings debited as planned.
    expect(balanceOf($borrower))->toBe(0.0)
        ->and(balanceOf($a))->toBe(4400.0)
        ->and(balanceOf($b))->toBe(4800.0);

    // The loan is repaid in full and closed.
    $loan->refresh();
    expect((float) $loan->outstanding_balance)->toBe(0.0)
        ->and($loan->status->value)->toBe('closed');

    // Guarantees drawn on, and no longer holding anything.
    expect($pledgeA->fresh()->status)->toBe('invoked')
        ->and((float) $pledgeA->fresh()->recovered_amount)->toBe(600.0)
        ->and((float) $pledgeB->fresh()->recovered_amount)->toBe(200.0)
        ->and(app(LoanGuarantorServiceInterface::class)->heldAmount('individual', $a->id))->toBe(0.0);

    // One savings-repayment journal per line, each balanced: Dr savings, Cr portfolio.
    $journals = JournalEntry::where('id', '>', $journalsBefore)->with('lines')->get();
    expect($journals)->toHaveCount(3);
    foreach ($journals as $je) {
        expect(round($je->lines->sum('debit'), 2))->toBe(round($je->lines->sum('credit'), 2));
    }
    expect($journals->flatMap->lines->where('account_id', $this->savingsGl->id)->sum('debit'))->toEqual(1000)
        ->and($journals->flatMap->lines->where('account_id', $this->portfolio->id)->sum('credit'))->toEqual(1000);

    // The savings transactions are the guarantors' own, not the borrower's.
    expect(DB::table('transactions')->where('member_id', $a->id)->where('type', 'loan_repayment')->sum('amount'))->toEqual(600);

    // What the guarantors paid is now a recovery loan the borrower owes them.
    $recovery->refresh();
    expect($recovery->status)->toBe('executed')
        ->and($recovery->approved_by)->toBe($this->checker->id)
        ->and($recovery->recovery_loan_status)->toBe('open')
        ->and($recovery->recoveryLoanOutstanding())->toBe(800.0);
});

it('leaves the loan open when savings cannot cover it all', function () {
    $borrower = memberWithSavings(0);
    [$loan] = defaultedLoan($borrower, [[memberWithSavings(300), 300]]);

    $recovery = recoveries()->propose($loan, null, null, $this->maker->id);
    recoveries()->approve($recovery, $this->checker->id);

    $loan->refresh();
    expect((float) $loan->outstanding_balance)->toBe(700.0)
        ->and($loan->status->value)->toBe('arrears');
});

it('refuses to approve when savings have dropped since the proposal', function () {
    $a = memberWithSavings(5000);
    [$loan] = defaultedLoan(memberWithSavings(0), [[$a, 1000]]);
    $recovery = recoveries()->propose($loan, null, null, $this->maker->id);

    SavingsAccount::where('member_id', $a->id)->update(['balance' => 500]);

    $errors = validationErrors(fn () => recoveries()->approve($recovery, $this->checker->id));
    expect(implode(' ', $errors['recovery']))->toContain('propose it again');
    expect((float) $loan->fresh()->outstanding_balance)->toBe(1000.0);
});

it('rejects a proposal without touching any savings', function () {
    $a = memberWithSavings(5000);
    [$loan] = defaultedLoan(memberWithSavings(0), [[$a, 1000]]);
    $recovery = recoveries()->propose($loan, null, null, $this->maker->id);

    recoveries()->reject($recovery, 'Borrower agreed a payment plan', $this->checker->id);

    expect($recovery->fresh()->status)->toBe('rejected')
        ->and(balanceOf($a))->toBe(5000.0);
});

it('schedules the recovery loan in equal monthly instalments', function () {
    setGuarantorSetting('sacco-guarantor-recovery-loan-term-months', 3);
    [$loan] = defaultedLoan(memberWithSavings(0), [[memberWithSavings(5000), 1000]]);
    $recovery = recoveries()->propose($loan, null, null, $this->maker->id);
    recoveries()->approve($recovery, $this->checker->id);

    $schedule = recoveries()->schedule($recovery->fresh());

    expect($schedule)->toHaveCount(3)
        ->and(array_column($schedule, 'amount'))->toBe([333.33, 333.33, 333.34])
        ->and($schedule[0]['due_date'])->toBe(now()->addMonthNoOverflow()->toDateString());
});

it('pays the borrower\'s repayments into the guarantors\' savings, free of deposit charges', function () {
    $a = memberWithSavings(5000);
    $b = memberWithSavings(5000);
    [$loan] = defaultedLoan(memberWithSavings(0), [[$a, 750], [$b, 250]]);
    $recovery = recoveries()->propose($loan, null, null, $this->maker->id);
    recoveries()->approve($recovery, $this->checker->id);
    // A deposit fee that ordinary deposits into A's account would pay.
    SavingsProductCharge::factory()->create([
        'savings_product_id' => SavingsAccount::where('member_id', $a->id)->value('savings_product_id'),
        'type' => 'deposit', 'charge_type' => 'amount', 'amount' => 5,
    ]);
    $journalsBefore = JournalEntry::max('id');
    $chargesBefore = DB::table('transactions')->where('type', 'charge')->count();

    $repayment = recoveries()->repay($recovery->fresh(), 400, ['payment_mode' => 'cash'], $this->checker->id);

    // 400 split 750:250 of what each is owed.
    expect(balanceOf($a))->toBe(4250.0 + 300)
        ->and(balanceOf($b))->toBe(4750.0 + 100)
        ->and($repayment->allocations)->toHaveCount(2);

    // Each share is a deposit: Dr cash, Cr the guarantor's savings.
    $deposits = JournalEntry::where('id', '>', $journalsBefore)->with('lines')->get();
    expect($deposits)->toHaveCount(2);
    foreach ($deposits as $je) {
        expect(round($je->lines->sum('debit'), 2))->toBe(round($je->lines->sum('credit'), 2));
    }
    expect($deposits->flatMap->lines->where('account_id', $this->cash->id)->sum('debit'))->toEqual(400)
        ->and($deposits->flatMap->lines->where('account_id', $this->savingsGl->id)->sum('credit'))->toEqual(400);
    expect(DB::table('transactions')->where('type', 'charge')->count())->toBe($chargesBefore);

    expect($recovery->fresh()->recoveryLoanOutstanding())->toBe(600.0);

    recoveries()->repay($recovery->fresh(), 600, [], $this->checker->id);
    expect($recovery->fresh()->recovery_loan_status)->toBe('settled')
        ->and(balanceOf($a))->toBe(5000.0)
        ->and(balanceOf($b))->toBe(5000.0);
});

it('refuses a repayment larger than what is still owed', function () {
    [$loan] = defaultedLoan(memberWithSavings(0), [[memberWithSavings(5000), 1000]]);
    $recovery = recoveries()->propose($loan, null, null, $this->maker->id);
    recoveries()->approve($recovery, $this->checker->id);

    expect(fn () => recoveries()->repay($recovery->fresh(), 1000.01, [], $this->checker->id))
        ->toThrow(ValidationException::class);
});

it('runs the whole recovery over the API', function () {
    $a = memberWithSavings(5000);
    [$loan] = defaultedLoan(memberWithSavings(0), [[$a, 1000]]);
    $host = ['Host' => 'test.mfukopro.test'];

    $this->withHeaders($host)
        ->getJson("/api/v1/tenant/loans/{$loan->id}/guarantor-recovery/plan")
        ->assertOk()
        ->assertJsonPath('data.eligible', true)
        ->assertJsonPath('data.guarantor_amount', 1000);

    $id = $this->withHeaders($host)
        ->postJson("/api/v1/tenant/loans/{$loan->id}/guarantor-recovery", ['notes' => 'Default'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending_approval')
        ->json('data.id');

    $this->withHeaders($host)
        ->postJson("/api/v1/tenant/guarantor-recoveries/{$id}/approve")
        ->assertStatus(422);

    $this->actingAs($this->checker, 'sanctum')
        ->withHeaders($host)
        ->postJson("/api/v1/tenant/guarantor-recoveries/{$id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'executed')
        ->assertJsonPath('data.recovery_loan.outstanding', 1000);

    $this->withHeaders($host)
        ->postJson("/api/v1/tenant/guarantor-recoveries/{$id}/repayments", ['amount' => 250, 'payment_mode' => 'cash'])
        ->assertCreated()
        ->assertJsonPath('data.recovery_loan.outstanding', 750)
        ->assertJsonCount(1, 'data.repayments');

    $this->withHeaders($host)
        ->getJson('/api/v1/tenant/guarantor-recoveries?recovery_loan_status=open')
        ->assertOk()
        ->assertJsonPath('data.0.id', $id);
});

it('shows members what they owe and what they are owed', function () {
    $borrower = memberWithSavings(0);
    $guarantor = memberWithSavings(5000);
    [$loan] = defaultedLoan($borrower, [[$guarantor, 1000]]);
    $recovery = recoveries()->propose($loan, null, null, $this->maker->id);
    recoveries()->approve($recovery, $this->checker->id);
    $url = 'http://test.mfukopro.test/api/v1/tenant/member/guarantor-recoveries';

    $this->actingAs($borrower, 'sanctum')->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.owed_by_me.0.outstanding', 1000)
        ->assertJsonCount(0, 'data.owed_to_me');

    $this->actingAs($guarantor, 'sanctum')->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.owed_to_me.0.owed', 1000)
        ->assertJsonPath('data.owed_to_me.0.borrower_name', $borrower->name);
});
