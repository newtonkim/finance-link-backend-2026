<?php

use App\Jobs\SendQueuedNotificationsAndMessages;
use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Services\GuarantorArrearsService;
use App\Tenant\Services\NotificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/guarantor_helpers.php';

/**
 * Arrears warnings: guarantors hear when the loan they stand behind has been overdue
 * long enough, once per spell of arrears unless reminders are switched on.
 */
beforeEach(function () {
    Bus::fake();

    $this->staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->staff, 'sanctum');

    seedGuarantorSettings();
    setGuarantorSetting('sacco-guarantor-arrears-notice-days', 30);
});

function arrears(): GuarantorArrearsService
{
    return app(GuarantorArrearsService::class);
}

/** An unpaid instalment of $amount that fell due $daysAgo days ago. */
function overdueInstalment(Loan $loan, int $daysAgo, float $amount = 100, int $no = 1): void
{
    DB::table('loan_repayment_schedule')->insert([
        'loan_id' => $loan->id,
        'installment_no' => $no,
        'due_date' => now()->subDays($daysAgo)->toDateString(),
        'principal_due' => $amount,
        'interest_due' => 0,
        'total_due' => $amount,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** A disbursed loan with one guarantor whose guarantee is locked to it. */
function guaranteedLoan(float $pledged = 400): array
{
    $application = guarantorApplication();
    $guarantor = memberWithSavings(1000);
    $pledge = pledge($application, $guarantor, $pledged);
    $loan = loanFor($application);
    app(LoanGuarantorServiceInterface::class)->lockForLoan($application->fresh(), $loan);

    return [$loan, $pledge->fresh(), $guarantor];
}

function enableGuarantorSms(): void
{
    DB::table('system_settings')->insert([
        'settings_name' => 'sacco-notify-the-guarantor',
        'settings_module' => 'system-sms-notifications',
        'settings_status' => 'active',
        'settings_action' => json_encode(['action' => 1, 'attr' => 'switch']),
        'system_type' => 'system',
        'created_by' => 0,
        'updated_by' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('message_notification_settings')->insert([
        'code' => 'SMS-'.uniqid(), 'cost' => 10, 'platform_cost' => 5, 'channel' => 'sms',
        'length_min' => 0, 'length_max' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tenant_billing_account')->insert([
        'code' => 'BILL-'.uniqid(), 'name' => 'SMS', 'account_balance' => 1000, 'channel' => 'sms',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('measures how far behind a guaranteed loan is', function () {
    [$loan] = guaranteedLoan();
    overdueInstalment($loan, 40, 100, 1);
    overdueInstalment($loan, 10, 150, 2);

    $row = arrears()->overdueLoans()->get($loan->id);

    expect($row['days_past_due'])->toBe(40)
        ->and($row['arrears_amount'])->toBe(250.0)
        ->and($row['arrears_since'])->toBe(now()->subDays(40)->toDateString());
});

it('leaves out loans nobody guarantees, and instalments not yet due or already paid', function () {
    $unguaranteed = loanFor(guarantorApplication());
    overdueInstalment($unguaranteed, 40);

    [$loan] = guaranteedLoan();
    DB::table('loan_repayment_schedule')->insert([
        'loan_id' => $loan->id, 'installment_no' => 1, 'due_date' => now()->subDays(40)->toDateString(),
        'principal_due' => 100, 'interest_due' => 0, 'total_due' => 100, 'principal_paid' => 100,
        'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
    ]);
    overdueInstalment($loan, -5, 100, 2); // due in five days

    expect(arrears()->overdueLoans())->toBeEmpty();
});

it('counts days overdue after the product\'s grace period', function () {
    [$loan] = guaranteedLoan();
    DB::table('loan_products')->where('id', $loan->loanApplication->loan_product_id)->update(['grace_period' => 7]);
    DB::table('loans')->where('id', $loan->id)->update(['loan_product_id' => $loan->loanApplication->loan_product_id]);
    overdueInstalment($loan, 40);

    expect(arrears()->overdueLoans()->get($loan->id)['days_past_due'])->toBe(33);
});

it('warns guarantors once the loan is overdue long enough', function () {
    [$loan, $pledge] = guaranteedLoan();
    overdueInstalment($loan, 35);

    expect(arrears()->notifyDue())->toBe(1);

    $pledge->refresh();
    expect($pledge->arrears_notified_at)->not->toBeNull()
        ->and($pledge->arrears_notice_count)->toBe(1);
});

it('does not warn before the threshold', function () {
    [$loan, $pledge] = guaranteedLoan();
    overdueInstalment($loan, 20);

    expect(arrears()->notifyDue())->toBe(0)
        ->and($pledge->fresh()->arrears_notified_at)->toBeNull();
});

it('warns only once in the same spell of arrears', function () {
    [$loan] = guaranteedLoan();
    overdueInstalment($loan, 35);

    arrears()->notifyDue();
    $this->travel(10)->days();

    expect(arrears()->notifyDue())->toBe(0);
});

it('warns again when the loan falls behind again after catching up', function () {
    [$loan, $pledge] = guaranteedLoan();
    overdueInstalment($loan, 35, 100, 1);
    arrears()->notifyDue();

    // The member pays that instalment, then misses a later one.
    DB::table('loan_repayment_schedule')->where('loan_id', $loan->id)->update(['status' => 'paid', 'principal_paid' => 100]);
    $this->travel(40)->days();
    overdueInstalment($loan, 31, 100, 2);

    expect(arrears()->notifyDue())->toBe(1)
        ->and($pledge->fresh()->arrears_notice_count)->toBe(2);
});

it('repeats the warning when reminders are on', function () {
    setGuarantorSetting('sacco-guarantor-arrears-reminder-days', 7);
    [$loan] = guaranteedLoan();
    overdueInstalment($loan, 35);
    arrears()->notifyDue();

    $this->travel(6)->days();
    expect(arrears()->notifyDue())->toBe(0);

    $this->travel(1)->days();
    expect(arrears()->notifyDue())->toBe(1);
});

it('sends nothing while the warning is switched off', function () {
    setGuarantorSetting('sacco-guarantor-arrears-notice-days', 0);
    [$loan] = guaranteedLoan();
    overdueInstalment($loan, 90);

    expect(arrears()->notifyDue())->toBe(0);
});

it('does not warn guarantors of a closed loan', function () {
    [$loan] = guaranteedLoan();
    overdueInstalment($loan, 35);
    $loan->update(['status' => 'closed']);

    expect(arrears()->notifyDue())->toBe(0);
});

it('keeps warning guarantors of a written-off loan', function () {
    [$loan] = guaranteedLoan();
    overdueInstalment($loan, 35);
    $loan->update(['status' => 'written_off']);

    expect(arrears()->notifyDue())->toBe(1);
});

it('lets staff warn a loan\'s guarantors early, but only for an overdue loan', function () {
    [$loan, $pledge] = guaranteedLoan();

    expect(fn () => arrears()->notifyLoan($loan))->toThrow(ValidationException::class);

    overdueInstalment($loan, 3);
    expect(arrears()->notifyLoan($loan))->toBe(1)
        ->and($pledge->fresh()->arrears_notice_count)->toBe(1);
});

it('texts the guarantor how far behind the loan is and what is held', function () {
    enableGuarantorSms();
    [$loan, $pledge, $guarantor] = guaranteedLoan(400);
    overdueInstalment($loan, 35, 250);

    arrears()->notifyDue();

    $message = DB::table('sent_message_notifications')->latest('id')->first();
    expect($message->body)->toContain($guarantor->name)
        ->toContain($loan->loan_no)
        ->toContain('35 days overdue')
        ->toContain('250.00')
        ->toContain('400.00');
});

it('sends from a scheduled run, with no one signed in', function () {
    enableGuarantorSms();
    [$loan] = guaranteedLoan();
    overdueInstalment($loan, 35);
    Auth::forgetGuards();

    arrears()->notifyDue('acme');

    $message = DB::table('sent_message_notifications')->latest('id')->first();
    expect($message)->not->toBeNull()
        ->and((int) $message->sender_id)->toBe(0);
});

it('dispatches the send job for the tenant it is given, with no request to read it from', function () {
    // The notifier hands the command's tenant through to this; its own dispatch waits
    // for the database transaction to commit, which never happens inside a test.
    (new NotificationService)->runTheQue('acme');

    Bus::assertDispatched(SendQueuedNotificationsAndMessages::class, function ($job) {
        return (new ReflectionProperty($job, 'subdomainName'))->getValue($job) === 'acme';
    });
});

it('runs the scheduled command cleanly', function () {
    expect(Artisan::call('loan:notify-guarantors-arrears'))->toBe(0);
});

it('lists overdue guaranteed loans for staff, most overdue first', function () {
    [$lessLate] = guaranteedLoan();
    overdueInstalment($lessLate, 10);
    [$moreLate, $pledge] = guaranteedLoan(300);
    overdueInstalment($moreLate, 50);

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->getJson('/api/v1/tenant/loan-guarantors/arrears')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.loan_no', $moreLate->loan_no)
        ->assertJsonPath('data.0.days_past_due', 50)
        ->assertJsonPath('data.0.guaranteed_amount', 300)
        ->assertJsonPath('data.0.guarantors.0.id', $pledge->id);
});

it('lets staff warn guarantors over the API', function () {
    [$loan] = guaranteedLoan();
    overdueInstalment($loan, 5);

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson("/api/v1/tenant/loans/{$loan->id}/guarantors/notify-arrears")
        ->assertOk()
        ->assertJsonPath('data.notified', 1);
});

it('shows a member how far behind a loan they guarantee is', function () {
    [$loan, $pledge, $guarantor] = guaranteedLoan();
    overdueInstalment($loan, 12, 80);

    $this->actingAs($guarantor, 'sanctum')
        ->getJson('http://test.mfukopro.test/api/v1/tenant/member/guarantee-requests')
        ->assertOk()
        ->assertJsonPath('data.0.loan_arrears.days_past_due', 12)
        ->assertJsonPath('data.0.loan_arrears.arrears_amount', 80);
});
