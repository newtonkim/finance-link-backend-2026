<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Services\SavingsTransactionPostingService;
use App\Tenant\Services\TenantSavingsAccountService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/guarantor_helpers.php';

/**
 * Holds on guarantors' savings: a binding guarantee stops the guarantor taking
 * their savings below what they guarantee, until the loan closes.
 */
beforeEach(function () {
    Bus::fake();

    $this->staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->staff, 'sanctum');

    seedGuarantorSettings();
});

function holds(): LoanGuarantorServiceInterface
{
    return app(LoanGuarantorServiceInterface::class);
}

function accountOf(Member $member): SavingsAccount
{
    return SavingsAccount::where('member_id', $member->id)->firstOrFail();
}

/** A bare loan row for $application, enough for holds to point at. */
function loanFor(LoanApplication $application, string $status = 'disbursed'): Loan
{
    $loanId = DB::table('loans')->insertGetId([
        'loan_application_id' => $application->id,
        'loan_no' => 'LN-'.uniqid(),
        'member_id' => $application->member_id,
        'principal' => 1000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $application->update(['status' => LoanApplication::STATUS_DISBURSED, 'disbursed_loan_id' => $loanId]);

    return Loan::findOrFail($loanId);
}

it('stops a guarantor withdrawing below what they guarantee', function () {
    $guarantor = memberWithSavings(1000);
    pledge(guarantorApplication(), $guarantor, 700);

    $posting = app(SavingsTransactionPostingService::class);
    $account = accountOf($guarantor);

    expect(fn () => $posting->assertCanWithdraw($account, 301))->toThrow(ValidationException::class);
    $posting->assertCanWithdraw($account, 300);

    expect(holds()->withdrawable('individual', $guarantor->id))
        ->toBe(['balance' => 1000.0, 'held' => 700.0, 'available' => 300.0]);
});

it('says how much is held and how much can still be taken out', function () {
    $guarantor = memberWithSavings(1000);
    pledge(guarantorApplication(), $guarantor, 700);

    $errors = validationErrors(fn () => holds()->assertCanDebit('individual', $guarantor->id, 500));

    expect($errors['amount'][0])->toContain('700.00')->toContain('300.00');
});

it('adds up holds across every savings account the guarantor has', function () {
    $guarantor = memberWithSavings(600);
    SavingsAccount::factory()->create(['member_id' => $guarantor->id, 'balance' => 400]);
    pledge(guarantorApplication(), $guarantor, 700);

    // 1000 across two accounts, 700 held: 300 can go from either.
    expect(fn () => holds()->assertCanDebit('individual', $guarantor->id, 301))->toThrow(ValidationException::class);
    holds()->assertCanDebit('individual', $guarantor->id, 300);
});

it('does not hold savings for a request the guarantor has not accepted', function () {
    setGuarantorSetting('sacco-guarantor-consent-required', 1);
    $guarantor = memberWithSavings(1000);
    $pledge = pledge(guarantorApplication(), $guarantor, 700);

    expect($pledge->status)->toBe('requested')
        ->and(holds()->heldAmount('individual', $guarantor->id))->toBe(0.0);

    holds()->respond($pledge->fresh(), true, null, LoanApplicationGuarantor::CHANNEL_MEMBER_PORTAL);

    expect(holds()->heldAmount('individual', $guarantor->id))->toBe(700.0);
});

it('re-checks the guarantor can still afford it when they accept', function () {
    setGuarantorSetting('sacco-guarantor-consent-required', 1);
    $guarantor = memberWithSavings(1000);
    $pledge = pledge(guarantorApplication(), $guarantor, 700);

    // Not held yet, so the guarantor was free to withdraw in the meantime.
    accountOf($guarantor)->update(['balance' => 500]);

    expect(fn () => holds()->respond($pledge->fresh(), true, null, LoanApplicationGuarantor::CHANNEL_MEMBER_PORTAL))
        ->toThrow(ValidationException::class);
    expect($pledge->fresh()->status)->toBe('requested');
});

it('holds nothing while the hold setting is off', function () {
    setGuarantorSetting('sacco-guarantor-hold-savings', 0);
    $guarantor = memberWithSavings(1000);
    pledge(guarantorApplication(), $guarantor, 700);

    holds()->assertCanDebit('individual', $guarantor->id, 1000);
    expect(holds()->withdrawable('individual', $guarantor->id)['available'])->toBe(1000.0);
});

it('releases the hold when the application is rejected', function () {
    $guarantor = memberWithSavings(1000);
    $application = guarantorApplication();
    pledge($application, $guarantor, 700);

    $application->update(['status' => LoanApplication::STATUS_REJECTED]);

    expect(holds()->heldAmount('individual', $guarantor->id))->toBe(0.0);
    holds()->assertCanDebit('individual', $guarantor->id, 1000);
});

it('locks binding guarantees to the loan at disbursement and drops unanswered requests', function () {
    setGuarantorSetting('sacco-guarantor-consent-required', 1);
    $application = guarantorApplication();
    $accepting = memberWithSavings(1000);
    $silent = memberWithSavings(1000);
    $accepted = pledge($application, $accepting, 400);
    $unanswered = pledge($application, $silent, 400);
    holds()->respond($accepted->fresh(), true, null, LoanApplicationGuarantor::CHANNEL_MEMBER_PORTAL);

    $loan = loanFor($application);
    expect(holds()->lockForLoan($application->fresh(), $loan))->toBe(1);

    $accepted->refresh();
    expect($accepted->status)->toBe('locked')
        ->and($accepted->loan_id)->toBe($loan->id)
        ->and($accepted->locked_at)->not->toBeNull()
        ->and(holds()->heldAmount('individual', $accepting->id))->toBe(400.0);

    $unanswered = LoanApplicationGuarantor::withTrashed()->find($unanswered->id);
    expect($unanswered->status)->toBe('withdrawn')
        ->and($unanswered->release_reason)->toBe('unanswered_at_disbursement')
        ->and($unanswered->trashed())->toBeFalse()
        ->and(holds()->freeCapacity('individual', $silent->id))->toBe(1000.0);
});

it('releases the hold when the loan closes', function () {
    $guarantor = memberWithSavings(1000);
    $application = guarantorApplication();
    $pledge = pledge($application, $guarantor, 700);
    $loan = loanFor($application);
    holds()->lockForLoan($application->fresh(), $loan);

    $loan->update(['status' => 'closed']);

    $pledge->refresh();
    expect($pledge->status)->toBe('released')
        ->and($pledge->release_reason)->toBe('loan_closed')
        ->and($pledge->released_at)->not->toBeNull()
        ->and(holds()->heldAmount('individual', $guarantor->id))->toBe(0.0);
    holds()->assertCanDebit('individual', $guarantor->id, 1000);
});

it('keeps the hold on a written-off loan', function () {
    $guarantor = memberWithSavings(1000);
    $application = guarantorApplication();
    pledge($application, $guarantor, 700);
    $loan = loanFor($application);
    holds()->lockForLoan($application->fresh(), $loan);

    $loan->update(['status' => 'written_off']);

    expect(holds()->heldAmount('individual', $guarantor->id))->toBe(700.0);
});

it('lets a guarantor put held savings toward the very loan they guarantee', function () {
    $guarantor = memberWithSavings(1000);
    $application = guarantorApplication();
    pledge($application, $guarantor, 700);
    $loan = loanFor($application);
    holds()->lockForLoan($application->fresh(), $loan);

    expect(fn () => holds()->assertCanDebit('individual', $guarantor->id, 900))->toThrow(ValidationException::class);
    holds()->assertCanDebit('individual', $guarantor->id, 900, $loan->id);
});

it('refuses a legacy withdrawal that would breach the hold', function () {
    $guarantor = memberWithSavings(1000);
    pledge(guarantorApplication(), $guarantor, 700);

    request()->replace(['amount' => 500, 'account_id' => accountOf($guarantor)->id]);
    $result = app(TenantSavingsAccountService::class)->memberAccountWithdrawal();

    expect($result['code'] ?? null)->toBe(422)
        ->and($result['message'])->toContain('held as a guarantee');
    expect((float) accountOf($guarantor)->balance)->toBe(1000.0);
});

it('lets a guarantor move money between their own accounts but not to someone else', function () {
    $guarantor = memberWithSavings(1000);
    $ownOther = SavingsAccount::factory()->create(['member_id' => $guarantor->id, 'balance' => 0]);
    $someoneElse = SavingsAccount::factory()->create(['member_id' => Member::factory()->create()->id, 'balance' => 0]);
    pledge(guarantorApplication(), $guarantor, 700);

    $service = app(TenantSavingsAccountService::class);
    $from = accountOf($guarantor)->id;

    expect($service->makeAccountsComputation($from, $someoneElse->id, 500))->toBeFalse();
    expect($service->makeAccountsComputation($from, $ownOther->id, 500))->toBeTrue();
});

it('refuses a transfer to another member over the API when it would breach the hold', function () {
    $guarantor = memberWithSavings(1000);
    $someoneElse = SavingsAccount::factory()->create(['member_id' => Member::factory()->create()->id, 'balance' => 0]);
    pledge(guarantorApplication(), $guarantor, 700);

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson('/api/v1/tenant/savings-transfer', [
            'from_account_id' => accountOf($guarantor)->id,
            'to_account_id' => $someoneElse->id,
            'amount' => 500,
            'transfer_date' => now()->toDateString(),
            'transaction_reference' => 'TRF-'.uniqid(),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');
});

it('refuses closing an account whose balance the member\'s guarantees need', function () {
    $guarantor = memberWithSavings(1000);
    pledge(guarantorApplication(), $guarantor, 700);

    request()->replace(['id' => accountOf($guarantor)->id]);

    expect(fn () => app(TenantSavingsAccountService::class)->memberAccountDelete())
        ->toThrow(ValidationException::class);
    expect(SavingsAccount::find(accountOf($guarantor)->id))->not->toBeNull();
});

it('holds a group\'s savings and refuses deleting a group that guarantees a loan', function () {
    $group = SavingsGroup::factory()->create();
    $account = SavingsAccount::factory()->create();
    DB::table('group_savings_accounts')->insert([
        'savings_group_id' => $group->id,
        'savings_product_id' => $account->savings_product_id,
        'balance' => 1000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    pledge(guarantorApplication(), $group, 600);

    expect(fn () => holds()->assertCanDebit('group', $group->id, 401))->toThrow(ValidationException::class);
    holds()->assertCanDebit('group', $group->id, 400);

    request()->replace(['id' => $group->id]);
    $result = app(TenantSavingsAccountService::class)->groupAccountDelete();

    // The guard throws before anything is deleted. (The legacy transaction() helper
    // rolls back twice on error, which in a test also undoes the test's own wrapping
    // transaction, so the group cannot be looked up again here.)
    expect($result['message'] ?? '')->toContain('cannot be deleted');
});

it('reports a guarantor\'s held and available savings to staff', function () {
    $guarantor = memberWithSavings(1000);
    $application = guarantorApplication();
    pledge($application, $guarantor, 700);

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->getJson("/api/v1/tenant/loan-guarantors/capacity?guarantor_type=individual&guarantor_id={$guarantor->id}")
        ->assertOk()
        ->assertJsonPath('data.held_amount', 700)
        ->assertJsonPath('data.available_to_withdraw', 300)
        ->assertJsonPath('data.guarantees.0.application_no', $application->application_no);
});

it('shows a member what their guarantees hold back', function () {
    $guarantor = memberWithSavings(1000);
    $application = guarantorApplication();
    pledge($application, $guarantor, 700);
    $loan = loanFor($application);
    holds()->lockForLoan($application->fresh(), $loan);

    $this->actingAs($guarantor, 'sanctum')
        ->getJson('http://test.mfukopro.test/api/v1/tenant/member/guarantee-requests')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'locked')
        ->assertJsonPath('savings.held', 700)
        ->assertJsonPath('savings.available', 300);
});

it('backfills guarantees on loans disbursed before holds existed', function () {
    $open = guarantorApplication();
    $openPledge = pledge($open, memberWithSavings(1000), 400);
    $openLoan = loanFor($open);

    $closed = guarantorApplication();
    $closedPledge = pledge($closed, memberWithSavings(1000), 400);
    loanFor($closed, 'closed');

    (require database_path('migrations/tenant/2026_09_29_000001_add_guarantee_holds.php'))->up();

    expect($openPledge->fresh()->status)->toBe('locked')
        ->and($openPledge->fresh()->loan_id)->toBe($openLoan->id)
        ->and($closedPledge->fresh()->status)->toBe('released')
        ->and($closedPledge->fresh()->release_reason)->toBe('loan_closed');
});
