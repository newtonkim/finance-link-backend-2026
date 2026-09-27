<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Modules\Loans\Models\LoanReschedule;
use App\Tenant\Modules\Loans\Services\GuarantorReportService;
use App\Tenant\Modules\Loans\Services\LoanRescheduleService;
use App\Tenant\Modules\Loans\Services\LoanTopupService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/guarantor_helpers.php';

/**
 * Guarantees over the life of a running loan: replacing a guarantor, a guarantor
 * asking to be replaced, top-ups carrying guarantors over, reschedules telling
 * them, and the staff reports.
 */
beforeEach(function () {
    Bus::fake();

    $this->staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->staff, 'sanctum');

    seedGuarantorSettings();
});

function service(): LoanGuarantorServiceInterface
{
    return app(LoanGuarantorServiceInterface::class);
}

/** A disbursed loan whose guarantor's pledge is locked to it. */
function runningLoan(float $pledged = 400, ?Member $guarantor = null): array
{
    $application = guarantorApplication();
    $guarantor ??= memberWithSavings(1000);
    $pledge = pledge($application, $guarantor, $pledged);
    $loan = loanFor($application);
    $loan->update(['outstanding_balance' => 1000, 'loan_product_id' => $application->loan_product_id, 'branch_id' => 1]);
    service()->lockForLoan($application->fresh(), $loan);

    return [$loan->fresh(), $pledge->fresh(), $guarantor];
}

function replaceWith(LoanApplicationGuarantor $old, Member $new, ?float $amount = null): LoanApplicationGuarantor
{
    return service()->substitute($old->fresh(), 'individual', $new->id, null, $amount, null, null);
}

// ─── Replacing a guarantor ─────────────────────────────────────────────────────

it('replaces a guarantor straight away when guarantors need not accept', function () {
    [$loan, $old, $oldGuarantor] = runningLoan(400);
    $newGuarantor = memberWithSavings(1000);

    $new = replaceWith($old, $newGuarantor);

    expect($new->status)->toBe('locked')
        ->and($new->loan_id)->toBe($loan->id)
        ->and($new->substitutes_id)->toBe($old->id);

    $old->refresh();
    expect($old->status)->toBe('released')
        ->and($old->release_reason)->toBe('substituted')
        ->and($old->substituted_by_id)->toBe($new->id);

    // The hold moves with the guarantee.
    expect(service()->heldAmount('individual', $oldGuarantor->id))->toBe(0.0)
        ->and(service()->heldAmount('individual', $newGuarantor->id))->toBe(400.0);
});

it('keeps the old guarantor until the replacement accepts', function () {
    [$loan, $old, $oldGuarantor] = runningLoan(400);
    // Switched on once the loan is running, so its own guarantee is already locked.
    setGuarantorSetting('sacco-guarantor-consent-required', 1);
    $newGuarantor = memberWithSavings(1000);

    $new = replaceWith($old, $newGuarantor);

    expect($new->status)->toBe('requested')
        ->and($old->fresh()->status)->toBe('locked')
        ->and(service()->heldAmount('individual', $oldGuarantor->id))->toBe(400.0);

    service()->respond($new->fresh(), true, null, LoanApplicationGuarantor::CHANNEL_MEMBER_PORTAL);

    expect($new->fresh()->status)->toBe('locked')
        ->and($old->fresh()->status)->toBe('released')
        ->and(service()->heldAmount('individual', $oldGuarantor->id))->toBe(0.0);
});

it('leaves the old guarantor in place when the replacement declines', function () {
    [, $old] = runningLoan(400);
    // Switched on once the loan is running, so its own guarantee is already locked.
    setGuarantorSetting('sacco-guarantor-consent-required', 1);
    $new = replaceWith($old, memberWithSavings(1000));

    service()->respond($new->fresh(), false, 'No', LoanApplicationGuarantor::CHANNEL_MEMBER_PORTAL);

    expect($new->fresh()->status)->toBe('declined')
        ->and($old->fresh()->status)->toBe('locked');
});

it('refuses a second replacement while one is waiting', function () {
    [, $old] = runningLoan(400);
    // Switched on once the loan is running, so its own guarantee is already locked.
    setGuarantorSetting('sacco-guarantor-consent-required', 1);
    replaceWith($old, memberWithSavings(1000));

    expect(fn () => replaceWith($old, memberWithSavings(1000)))->toThrow(ValidationException::class);
});

it('needs the replacement to guarantee at least what is still at stake', function () {
    [, $old] = runningLoan(400);

    $errors = validationErrors(fn () => replaceWith($old, memberWithSavings(1000), 300));

    expect($errors['guarantee_amount'][0])->toContain('400.00');
});

it('refuses a replacement who cannot afford it', function () {
    [, $old] = runningLoan(400);

    expect(validationErrors(fn () => replaceWith($old, memberWithSavings(100))))->toHaveKey('guarantee_amount');
});

it('refuses someone already guaranteeing the loan', function () {
    [$loan, $old] = runningLoan(400);
    $second = memberWithSavings(1000);
    $application = LoanApplication::find($loan->loan_application_id);
    LoanApplicationGuarantor::create([
        'loan_application_id' => $application->id, 'guarantor_type' => 'individual', 'guarantor_id' => $second->id,
        'guarantee_amount' => 100, 'status' => 'locked', 'loan_id' => $loan->id,
    ]);

    expect(validationErrors(fn () => replaceWith($old, $second)))->toHaveKey('guarantor_id');
});

it('refuses to replace a guarantor once the loan has closed', function () {
    [$loan, $old] = runningLoan(400);
    $loan->update(['status' => 'closed']);

    expect(fn () => replaceWith($old, memberWithSavings(1000)))->toThrow(ValidationException::class);
});

it('replaces a guarantor over the API', function () {
    [, $old] = runningLoan(400);
    $newGuarantor = memberWithSavings(1000);

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson("/api/v1/tenant/loan-guarantors/{$old->id}/substitute", [
            'guarantor_type' => 'individual',
            'guarantor_id' => $newGuarantor->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'locked')
        ->assertJsonPath('data.substitutes_id', $old->id);
});

// ─── A guarantor asking to be replaced ─────────────────────────────────────────

it('lets a guarantor ask to be replaced, and shows staff who asked', function () {
    [$loan, $pledge, $guarantor] = runningLoan(400);

    $this->actingAs($guarantor, 'sanctum')
        ->postJson("http://test.mfukopro.test/api/v1/tenant/member/guarantee-requests/{$pledge->id}/request-release", [
            'reason' => 'Moving abroad',
        ])
        ->assertOk();

    expect($pledge->fresh()->release_requested_at)->not->toBeNull();

    $requests = app(GuarantorReportService::class)->releaseRequests();
    expect($requests)->toHaveCount(1)
        ->and($requests[0]['release_request_reason'])->toBe('Moving abroad')
        ->and($requests[0]['loan_no'])->toBe($loan->loan_no);
});

it('only lets a guarantee on a running loan ask for release', function () {
    $pledge = pledge(guarantorApplication(), memberWithSavings(1000), 100);

    expect(fn () => service()->requestRelease($pledge, null))->toThrow(ValidationException::class);
});

// ─── Top-ups ───────────────────────────────────────────────────────────────────

it('carries a topped-up loan\'s guarantors onto the new application', function () {
    [$loan, , $guarantor] = runningLoan(400);

    $result = app(LoanTopupService::class)->execute($loan, 500, 12, 'parallel', $this->staff->id);

    expect($result['flow'])->toBe('standard')
        ->and($result['guarantors_carried'])->toBe([$guarantor->name]);

    $carried = LoanApplicationGuarantor::where('loan_application_id', $result['application_id'])->first();
    expect($carried->guarantor_id)->toBe($guarantor->id)
        ->and((float) $carried->guarantee_amount)->toBe(400.0);
});

it('reports a guarantor who cannot be carried over', function () {
    // 1000 savings, 400 already held for the loan being topped up: 600 free, so
    // guaranteeing the new application for 400 more fits, but 700 would not.
    [$loan] = runningLoan(700);

    $result = app(LoanTopupService::class)->execute($loan, 500, 12, 'parallel', $this->staff->id);

    expect($result['guarantors_carried'])->toBe([])
        ->and($result['guarantors_skipped'])->toHaveCount(1)
        ->and($result['message'])->toContain('could not be carried over');
});

it('does not pay a guaranteed top-up out straight away', function () {
    [$loan] = runningLoan(400);
    DB::table('loan_products')->where('id', $loan->loan_product_id)->update(['topup_auto_disbursement' => true]);

    $result = app(LoanTopupService::class)->execute($loan->fresh(), 500, 12, 'parallel', $this->staff->id);

    expect($result['flow'])->toBe('standard');
});

// ─── Reschedules ───────────────────────────────────────────────────────────────

it('tells guarantors when their loan is rescheduled', function () {
    [$loan, $pledge] = runningLoan(400);
    $loan->update(['term_months' => 24]);
    $reschedule = new LoanReschedule(['new_maturity_date' => now()->addYears(2)->toDateString()]);

    $method = new ReflectionMethod(LoanRescheduleService::class, 'notifyGuarantors');
    $method->invoke(app(LoanRescheduleService::class), $loan->fresh(), $reschedule);

    expect($pledge->fresh()->reschedule_notified_at)->not->toBeNull();
});

// ─── Reports ───────────────────────────────────────────────────────────────────

it('reports each guarantor\'s exposure, most held first', function () {
    $big = memberWithSavings(2000);
    runningLoan(300, $big);
    runningLoan(500, $big);
    $small = memberWithSavings(1000);
    runningLoan(100, $small);

    $rows = app(GuarantorReportService::class)->exposure();

    expect($rows[0]['guarantor_id'])->toBe($big->id)
        ->and($rows[0]['guarantees'])->toBe(2)
        ->and($rows[0]['held_amount'])->toBe(800.0)
        ->and($rows[0]['savings_balance'])->toBe(2000.0)
        ->and($rows[0]['held_share'])->toBe(40.0)
        ->and($rows[1]['guarantor_id'])->toBe($small->id);
});

it('lists guarantors who have not answered yet, soonest deadline first', function () {
    setGuarantorSetting('sacco-guarantor-consent-required', 1);
    pledge(guarantorApplication(), memberWithSavings(1000), 100);

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->getJson('/api/v1/tenant/loan-guarantors/report?view=pending_consents')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_replacement', false);
});
