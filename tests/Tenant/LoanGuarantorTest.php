<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Modules\Loans\Services\LoanApplicationService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Services\TenantLoanUpdateOrCreateService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/guarantor_helpers.php';

/**
 * Guarantor rules as LoanGuarantorService enforces them. Settings are seeded from
 * GuarantorSettings so each test starts from the shipped defaults, and a test that
 * needs a different rule sets just that one with setGuarantorSetting().
 */
beforeEach(function () {
    // Saving guarantors queues SMS notifications; the job itself is not under test.
    Bus::fake();

    $this->staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->staff, 'sanctum');

    seedGuarantorSettings();
});

it('records a pledge within the guarantor\'s capacity', function () {
    $application = guarantorApplication();
    $guarantor = memberWithSavings(500);

    $pledge = pledge($application, $guarantor, 400);

    expect($pledge->status)->toBe('proposed')
        ->and((float) $pledge->guarantee_amount)->toBe(400.0)
        ->and($pledge->code)->toBe(sprintf('GRT%06d', $pledge->id));
});

it('refuses a pledge larger than the guarantor\'s savings allow', function () {
    $application = guarantorApplication();
    $guarantor = memberWithSavings(500);

    $errors = validationErrors(fn () => pledge($application, $guarantor, 501));

    expect($errors)->toHaveKey('guarantee_amount');
});

it('applies the exposure percentage to the guarantor\'s savings', function () {
    setGuarantorSetting('sacco-guarantor-maximum-exposure-percentage', 50);
    $application = guarantorApplication();
    $guarantor = memberWithSavings(1000);

    expect(fn () => pledge($application, $guarantor, 501))->toThrow(ValidationException::class);
    expect(pledge($application, $guarantor, 500)->exists)->toBeTrue();
});

it('counts pledges on other open applications against capacity', function () {
    $guarantor = memberWithSavings(1000);
    pledge(guarantorApplication(), $guarantor, 700);

    $service = app(LoanGuarantorServiceInterface::class);
    expect($service->freeCapacity('individual', $guarantor->id))->toBe(300.0);

    expect(fn () => pledge(guarantorApplication(), $guarantor, 301))->toThrow(ValidationException::class);
});

it('frees capacity once the other application is rejected', function () {
    $guarantor = memberWithSavings(1000);
    $first = guarantorApplication();
    pledge($first, $guarantor, 700);

    $first->update(['status' => LoanApplication::STATUS_REJECTED]);

    expect(app(LoanGuarantorServiceInterface::class)->freeCapacity('individual', $guarantor->id))->toBe(1000.0);
});

it('keeps a guarantee committed after disbursement until the loan is closed', function () {
    $guarantor = memberWithSavings(1000);
    $application = guarantorApplication();
    pledge($application, $guarantor, 700);
    $application->update(['status' => LoanApplication::STATUS_DISBURSED]);

    $loanId = DB::table('loans')->insertGetId([
        'loan_application_id' => $application->id,
        'loan_no' => 'LN-'.uniqid(),
        'member_id' => $application->member_id,
        'principal' => 1000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(LoanGuarantorServiceInterface::class);
    expect($service->freeCapacity('individual', $guarantor->id))->toBe(300.0);

    DB::table('loans')->where('id', $loanId)->update(['status' => 'closed']);
    expect($service->freeCapacity('individual', $guarantor->id))->toBe(1000.0);
});

it('stops the borrower guaranteeing their own loan', function () {
    $application = guarantorApplication();
    $borrower = Member::find($application->member_id);

    $errors = validationErrors(fn () => pledge($application, $borrower, 1));

    expect($errors)->toHaveKey('guarantor_id');
});

it('lets the borrower guarantee their own loan when self-guarantee is allowed', function () {
    setGuarantorSetting('sacco-guarantor-allow-self-guarantee', 1);
    $borrower = memberWithSavings(100);
    $application = guarantorApplication(['member_id' => $borrower->id]);

    expect(pledge($application, $borrower, 50)->exists)->toBeTrue();
});

it('refuses an inactive member while guarantors must be active members', function () {
    $application = guarantorApplication();
    $guarantor = memberWithSavings(500, ['status' => 'pending']);

    expect(validationErrors(fn () => pledge($application, $guarantor, 10)))->toHaveKey('guarantor_id');

    setGuarantorSetting('sacco-guarantor-must-be-an-active-member', 0);
    expect(pledge($application, $guarantor, 10)->exists)->toBeTrue();
});

it('enforces the maximum number of guarantors, preferring the product\'s own limit', function () {
    setGuarantorSetting('sacco-guarantor-maximum-number', 5);
    $application = guarantorApplication([], ['max_guarantors' => 2]);

    pledge($application, memberWithSavings(100), 10);
    pledge($application, memberWithSavings(100), 10);

    $errors = validationErrors(fn () => pledge($application, memberWithSavings(100), 10));
    expect($errors)->toHaveKey('guarantors');
});

it('updates an existing pledge instead of adding the guarantor twice', function () {
    $application = guarantorApplication();
    $guarantor = memberWithSavings(1000);

    $first = pledge($application, $guarantor, 400);
    // 900 fits only because the guarantor's own 400 is not counted against them twice.
    $second = pledge($application, $guarantor, 900);

    expect($second->id)->toBe($first->id)
        ->and((float) $second->fresh()->guarantee_amount)->toBe(900.0)
        ->and($application->guarantors()->count())->toBe(1);
});

it('withdraws a removed guarantor and restores them if added again', function () {
    $application = guarantorApplication();
    $guarantor = memberWithSavings(1000);
    $service = app(LoanGuarantorServiceInterface::class);

    $pledge = pledge($application, $guarantor, 400);
    $service->removeGuarantor($pledge, null);

    $removed = LoanApplicationGuarantor::withTrashed()->find($pledge->id);
    expect($removed->trashed())->toBeTrue()
        ->and($removed->status)->toBe('withdrawn')
        ->and($service->freeCapacity('individual', $guarantor->id))->toBe(1000.0);

    $again = pledge($application, $guarantor, 300);
    expect($again->id)->toBe($pledge->id)
        ->and($again->trashed())->toBeFalse()
        ->and($again->status)->toBe('proposed');
});

it('refuses guarantor changes once the application has been approved', function () {
    $application = guarantorApplication(['status' => LoanApplication::STATUS_APPROVED]);

    expect(validationErrors(fn () => pledge($application, memberWithSavings(100), 10)))->toHaveKey('application');
});

it('counts the borrower\'s free savings toward coverage', function () {
    setGuarantorSetting('sacco-guarantor-required-coverage-percentage', 100);
    setGuarantorSetting('sacco-guarantor-minimum-number', 1);

    $borrower = memberWithSavings(600);
    $application = guarantorApplication(['member_id' => $borrower->id, 'requested_amount' => 1000]);
    pledge($application, memberWithSavings(1000), 300);

    $summary = app(LoanGuarantorServiceInterface::class)->summary($application);

    expect($summary['pledged_amount'])->toBe(300.0)
        ->and($summary['borrower_free_savings'])->toBe(600.0)
        ->and($summary['covered_amount'])->toBe(900.0)
        ->and($summary['coverage_shortfall'])->toBe(100.0)
        ->and($summary['count_met'])->toBeTrue()
        ->and($summary['adequate'])->toBeFalse();

    pledge($application, memberWithSavings(1000), 100);
    expect(app(LoanGuarantorServiceInterface::class)->summary($application)['adequate'])->toBeTrue();
});

it('blocks submission until the minimum number of guarantors is met', function () {
    setGuarantorSetting('sacco-guarantor-minimum-number', 2);
    $application = guarantorApplication();
    pledge($application, memberWithSavings(100), 10);

    $errors = validationErrors(fn () => app(LoanApplicationService::class)->submit($application));
    expect($errors)->toHaveKey('guarantors');

    pledge($application, memberWithSavings(100), 10);
    expect(app(LoanApplicationService::class)->submit($application->fresh()))->toBeTrue();
});

it('lets an application without guarantors through when guarantors are not required', function () {
    setGuarantorSetting('sacco-guarantor-required-on-loan-application', 0);
    $application = guarantorApplication();

    expect(app(LoanApplicationService::class)->submit($application))->toBeTrue();
});

it('saves guarantors from the picker, reading each row\'s shape correctly', function () {
    $application = guarantorApplication();
    $member = memberWithSavings(1000);
    $account = SavingsAccount::where('member_id', $member->id)->first();
    $group = SavingsGroup::factory()->create();
    $groupAccountId = DB::table('group_savings_accounts')->insertGetId([
        'savings_group_id' => $group->id,
        'savings_product_id' => $account->savings_product_id,
        'balance' => 800,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    request()->replace([
        'application_id' => $application->id,
        'guarantors' => [
            // A member row: id is the savings account, member_id the member.
            json_encode(['id' => $account->id, 'member_id' => $member->id, 'account_id' => $account->id, 'type' => 'individual', 'name' => $member->name, 'contribution' => 200]),
            // A group row: id is the group.
            json_encode(['id' => $group->id, 'account_id' => $groupAccountId, 'type' => 'group', 'name' => $group->name, 'contribution' => 300]),
        ],
    ]);

    $summary = app(TenantLoanUpdateOrCreateService::class)->saveGuarantors();

    expect($summary['guarantor_count'])->toBe(2);
    expect(LoanApplicationGuarantor::where('guarantor_type', 'individual')->where('guarantor_id', $member->id)->exists())->toBeTrue();
    expect(LoanApplicationGuarantor::where('guarantor_type', 'group')->where('guarantor_id', $group->id)->value('guarantor_account_id'))->toBe($groupAccountId);
});

it('saves none of the picked guarantors when one breaks a rule', function () {
    $application = guarantorApplication();
    $fine = memberWithSavings(1000);
    $tooPoor = memberWithSavings(10);

    request()->replace([
        'application_id' => $application->id,
        'guarantors' => [
            ['member_id' => $fine->id, 'type' => 'individual', 'name' => $fine->name, 'contribution' => 100],
            ['member_id' => $tooPoor->id, 'type' => 'individual', 'name' => $tooPoor->name, 'contribution' => 100],
        ],
    ]);

    $errors = validationErrors(fn () => app(TenantLoanUpdateOrCreateService::class)->saveGuarantors());

    expect($errors['guarantee_amount'][0])->toContain($tooPoor->name)
        ->and($application->guarantors()->count())->toBe(0);
});

it('refuses to record a non-member while guarantors must be members', function () {
    $application = guarantorApplication();
    request()->replace(['application_id' => $application->id, 'full_name' => 'Outside Person']);

    $errors = validationErrors(fn () => app(TenantLoanUpdateOrCreateService::class)->saveGuarantorsNoneMember());

    expect($errors)->toHaveKey('guarantor');
});

it('adds, lists and removes guarantors over the API', function () {
    $application = guarantorApplication();
    $guarantor = memberWithSavings(1000);
    $host = ['Host' => 'test.mfukopro.test'];

    $this->withHeaders($host)
        ->postJson("/api/v1/tenant/loan-applications/{$application->id}/guarantors", [
            'guarantor_type' => 'individual',
            'guarantor_id' => $guarantor->id,
            'guarantee_amount' => 5000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('guarantee_amount');

    $created = $this->withHeaders($host)
        ->postJson("/api/v1/tenant/loan-applications/{$application->id}/guarantors", [
            'guarantor_type' => 'individual',
            'guarantor_id' => $guarantor->id,
            'guarantee_amount' => 400,
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', $guarantor->name)
        ->assertJsonPath('summary.guarantor_count', 1)
        ->json('data');

    $this->withHeaders($host)
        ->getJson("/api/v1/tenant/loan-applications/{$application->id}/guarantors")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('summary.adequate', true);

    $this->withHeaders($host)
        ->getJson("/api/v1/tenant/loan-guarantors/capacity?guarantor_type=individual&guarantor_id={$guarantor->id}")
        ->assertOk()
        ->assertJsonPath('data.free_capacity', 600);

    $this->withHeaders($host)
        ->deleteJson("/api/v1/tenant/loan-applications/{$application->id}/guarantors/{$created['id']}")
        ->assertOk()
        ->assertJsonPath('summary.guarantor_count', 0);
});
