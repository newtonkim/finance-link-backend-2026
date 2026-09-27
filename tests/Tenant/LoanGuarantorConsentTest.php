<?php

use App\Models\Staff;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Modules\Loans\Services\LoanApplicationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/guarantor_helpers.php';

/**
 * Guarantor consent: with "Guarantor must accept" on, a guarantee counts only once
 * the guarantor accepts, and an application whose guarantors have not all answered
 * waits in awaiting_guarantors until enough do.
 */
beforeEach(function () {
    Bus::fake();

    $this->staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->staff, 'sanctum');

    seedGuarantorSettings();
    setGuarantorSetting('sacco-guarantor-consent-required', 1);
    setGuarantorSetting('sacco-guarantor-consent-expiry-days', 5);
});

function guarantorService(): LoanGuarantorServiceInterface
{
    return app(LoanGuarantorServiceInterface::class);
}

function respondAs(LoanApplicationGuarantor $pledge, bool $accept, string $channel = LoanApplicationGuarantor::CHANNEL_MEMBER_PORTAL): LoanApplicationGuarantor
{
    return guarantorService()->respond($pledge->fresh(), $accept, $accept ? null : 'Not this time', $channel);
}

it('asks a new guarantor to accept, with a deadline', function () {
    $pledge = pledge(guarantorApplication(), memberWithSavings(1000), 400);

    expect($pledge->status)->toBe('requested')
        ->and($pledge->requested_at)->not->toBeNull()
        ->and($pledge->consent_expires_at->toDateString())->toBe(now()->addDays(5)->toDateString());
});

it('records a new guarantor as proposed when consent is off', function () {
    setGuarantorSetting('sacco-guarantor-consent-required', 0);

    expect(pledge(guarantorApplication(), memberWithSavings(1000), 400)->status)->toBe('proposed');
});

it('counts only accepted guarantees toward the application', function () {
    $application = guarantorApplication();
    $pledge = pledge($application, memberWithSavings(1000), 400);

    $summary = guarantorService()->summary($application);
    expect($summary['guarantor_count'])->toBe(0)
        ->and($summary['pending_count'])->toBe(1)
        ->and($summary['adequate'])->toBeFalse()
        ->and($summary['adequate_if_pending_accept'])->toBeTrue();

    respondAs($pledge, true);

    $summary = guarantorService()->summary($application);
    expect($summary['guarantor_count'])->toBe(1)
        ->and($summary['pending_count'])->toBe(0)
        ->and($summary['adequate'])->toBeTrue();
});

it('holds the guarantor\'s capacity while waiting for an answer', function () {
    $guarantor = memberWithSavings(1000);
    pledge(guarantorApplication(), $guarantor, 700);

    expect(guarantorService()->freeCapacity('individual', $guarantor->id))->toBe(300.0);
});

it('frees the capacity when the guarantor declines', function () {
    $guarantor = memberWithSavings(1000);
    $pledge = pledge(guarantorApplication(), $guarantor, 700);

    $declined = respondAs($pledge, false);

    expect($declined->status)->toBe('declined')
        ->and($declined->decline_reason)->toBe('Not this time')
        ->and(guarantorService()->freeCapacity('individual', $guarantor->id))->toBe(1000.0);
});

it('expires requests past their deadline and frees their capacity', function () {
    $guarantor = memberWithSavings(1000);
    $pledge = pledge(guarantorApplication(), $guarantor, 700);

    $this->travel(6)->days();

    expect(guarantorService()->expireOverdue())->toBe(1)
        ->and($pledge->fresh()->status)->toBe('expired')
        ->and(guarantorService()->freeCapacity('individual', $guarantor->id))->toBe(1000.0);
});

it('stops a guarantor answering an expired request, but lets staff record it', function () {
    $pledge = pledge(guarantorApplication(), memberWithSavings(1000), 400);
    $this->travel(6)->days();

    expect(fn () => respondAs($pledge, true))->toThrow(ValidationException::class);

    $accepted = respondAs($pledge, true, LoanApplicationGuarantor::CHANNEL_OFFICER);
    expect($accepted->status)->toBe('accepted')
        ->and($accepted->response_channel)->toBe('officer');
});

it('re-checks capacity before reviving an expired request', function () {
    $guarantor = memberWithSavings(1000);
    $first = pledge(guarantorApplication(), $guarantor, 700);
    $this->travel(6)->days();
    guarantorService()->expireOverdue();

    // The freed capacity has since been pledged elsewhere.
    pledge(guarantorApplication(), $guarantor, 600);

    expect(fn () => guarantorService()->requestConsent($first->fresh(), null))
        ->toThrow(ValidationException::class);
});

it('sends an expired request again with a fresh deadline', function () {
    $pledge = pledge(guarantorApplication(), memberWithSavings(1000), 400);
    $this->travel(6)->days();
    guarantorService()->expireOverdue();

    $again = guarantorService()->requestConsent($pledge->fresh(), null);

    expect($again->status)->toBe('requested')
        ->and($again->consent_expires_at->isFuture())->toBeTrue();
});

it('asks again when an accepted pledge is raised, but not when it is lowered', function () {
    $application = guarantorApplication();
    $guarantor = memberWithSavings(1000);
    respondAs(pledge($application, $guarantor, 400), true);

    expect(pledge($application, $guarantor, 300)->status)->toBe('accepted');
    expect(pledge($application, $guarantor, 500)->status)->toBe('requested');
});

it('puts a submitted application in awaiting_guarantors until enough accept', function () {
    $application = guarantorApplication();
    $pledge = pledge($application, memberWithSavings(1000), 400);

    app(LoanApplicationService::class)->submit($application);
    expect($application->fresh()->status)->toBe(LoanApplication::STATUS_AWAITING_GUARANTORS);

    respondAs($pledge, true);

    $application->refresh();
    expect($application->status)->toBe(LoanApplication::STATUS_SUBMITTED)
        ->and($application->submitted_at)->not->toBeNull();
});

it('keeps waiting when a guarantor declines, until a replacement accepts', function () {
    $application = guarantorApplication();
    $first = pledge($application, memberWithSavings(1000), 400);
    app(LoanApplicationService::class)->submit($application);

    respondAs($first, false);
    expect($application->fresh()->status)->toBe(LoanApplication::STATUS_AWAITING_GUARANTORS);

    $replacement = pledge($application->fresh(), memberWithSavings(1000), 400);
    respondAs($replacement, true);

    expect($application->fresh()->status)->toBe(LoanApplication::STATUS_SUBMITTED);
});

it('still refuses submission when even full acceptance would fall short', function () {
    setGuarantorSetting('sacco-guarantor-minimum-number', 2);
    $application = guarantorApplication();
    pledge($application, memberWithSavings(1000), 400);

    expect(fn () => app(LoanApplicationService::class)->submit($application))
        ->toThrow(ValidationException::class);
    expect($application->fresh()->status)->toBe(LoanApplication::STATUS_DRAFT);
});

it('asks guarantors recorded before consent was switched on when the application is submitted', function () {
    setGuarantorSetting('sacco-guarantor-consent-required', 0);
    $application = guarantorApplication();
    $pledge = pledge($application, memberWithSavings(1000), 400);
    setGuarantorSetting('sacco-guarantor-consent-required', 1);

    app(LoanApplicationService::class)->submit($application);

    expect($pledge->fresh()->status)->toBe('requested')
        ->and($application->fresh()->status)->toBe(LoanApplication::STATUS_AWAITING_GUARANTORS);
});

it('expires requests across tenants from the scheduled command', function () {
    $pledge = pledge(guarantorApplication(), memberWithSavings(1000), 400);
    $this->travel(6)->days();

    // No tenant rows in the test database, so exercise the per-tenant work directly
    // and check the command is registered and runs cleanly.
    expect(Artisan::call('loan:expire-guarantor-requests'))->toBe(0);
    expect(guarantorService()->expireOverdue())->toBe(1)
        ->and($pledge->fresh()->status)->toBe('expired');
});

it('lets staff record a declined answer with the signed form attached', function () {
    Storage::fake('public');
    $application = guarantorApplication();
    $pledge = pledge($application, memberWithSavings(1000), 400);

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->post("/api/v1/tenant/loan-applications/{$application->id}/guarantors/{$pledge->id}/consent", [
            'decision' => 'declined',
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    $response = $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->post("/api/v1/tenant/loan-applications/{$application->id}/guarantors/{$pledge->id}/consent", [
            'decision' => 'declined',
            'reason' => 'Guarantor withdrew at the branch',
            'document' => UploadedFile::fake()->create('consent.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.status', 'declined')
        ->assertJsonPath('data.response_channel', 'officer');

    $stored = $pledge->fresh();
    expect($stored->responded_by)->toBe($this->staff->id);
    Storage::disk('public')->assertExists($stored->consent_document_path);
    expect($response->json('data.consent_document_url'))->not->toBeNull();
});

it('lets staff resend a request over the API', function () {
    $application = guarantorApplication();
    $pledge = pledge($application, memberWithSavings(1000), 400);
    $this->travel(6)->days();

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson("/api/v1/tenant/loan-applications/{$application->id}/guarantors/{$pledge->id}/request-consent")
        ->assertOk()
        ->assertJsonPath('data.status', 'requested');
});

it('lets a member see and accept only their own guarantee requests', function () {
    $application = guarantorApplication();
    $guarantor = memberWithSavings(1000);
    $mine = pledge($application, $guarantor, 400);
    $someoneElses = pledge($application, memberWithSavings(1000), 400);
    $url = fn (string $path) => 'http://test.mfukopro.test/api/v1/tenant/member/'.$path;

    $this->actingAs($guarantor, 'sanctum');

    $this->getJson($url('guarantee-requests'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.loan_application.application_no', $application->application_no);

    $this->postJson($url("guarantee-requests/{$someoneElses->id}/accept"))->assertNotFound();

    $this->postJson($url("guarantee-requests/{$mine->id}/accept"))
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted');

    $this->postJson($url("guarantee-requests/{$mine->id}/decline"))
        ->assertStatus(422);

    expect($mine->fresh()->response_channel)->toBe('member_portal');
});

it('moves a waiting application on when the member accepts from the portal', function () {
    $application = guarantorApplication();
    $guarantor = memberWithSavings(1000);
    $pledge = pledge($application, $guarantor, 400);
    app(LoanApplicationService::class)->submit($application);

    $this->actingAs($guarantor, 'sanctum')
        ->postJson("http://test.mfukopro.test/api/v1/tenant/member/guarantee-requests/{$pledge->id}/accept")
        ->assertOk();

    expect($application->fresh()->status)->toBe(LoanApplication::STATUS_SUBMITTED);

    $history = DB::table('loan_application_status_history')
        ->where('loan_application_id', $application->id)
        ->where('to_status', LoanApplication::STATUS_SUBMITTED)
        ->first();
    expect($history->notes)->toBe('Enough guarantors have accepted.');
});

it('texts the guarantor the amount and deadline when asking them to accept', function () {
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
        'code' => 'SMS-TEST', 'cost' => 10, 'platform_cost' => 5, 'channel' => 'sms',
        'length_min' => 0, 'length_max' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tenant_billing_account')->insert([
        'code' => 'BILL-TEST', 'name' => 'SMS', 'account_balance' => 1000, 'channel' => 'sms',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $application = guarantorApplication();
    $guarantor = memberWithSavings(1000);
    pledge($application, $guarantor, 400);

    $message = DB::table('sent_message_notifications')
        ->where('from_module', 'loan-application-guarantor')
        ->latest('id')
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->body)->toContain($guarantor->name)
        ->and($message->body)->toContain($application->application_no)
        ->and($message->body)->toContain(now()->addDays(5)->format('j M Y'))
        ->and($message->body)->toContain('accept or decline');
});

it('records the guarantee even when the SMS cannot be queued', function () {
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
    // No SMS pricing or billing rows exist, so queueing the message fails.

    $pledge = pledge(guarantorApplication(), memberWithSavings(1000), 400);

    expect($pledge->fresh()->status)->toBe('requested');
});
