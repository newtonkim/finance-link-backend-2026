<?php

use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->staff = Staff::firstOrCreate(
        ['email' => 'admin@gap2-test.com'],
        [
            'name'            => 'Tenant Admin',
            'password'        => Hash::make('password'),
            'role'            => 'Admin',
            'is_tenant_admin' => true,
        ]
    );

    $this->income = ChartOfAccount::query()->where('gl_code', '42997')->first() ?? ChartOfAccount::create([
        'gl_code'         => '42997',
        'name'            => 'Test Charge Income',
        'account_type'    => 'INCOME',
        'account_subtype' => 'Fee Income',
        'normal_balance'  => 'CR',
        'level'           => 3,
        'is_control'      => false,
        'is_postable'     => true,
        'is_active'       => true,
    ]);
});

it('migration drops loan_product_ids column and hard-deletes decorative rows', function () {
    if (! Schema::connection('tenant')->hasColumn('general_charges', 'loan_product_ids')) {
        // Migration already ran (idempotent). Recreate the column so the test exercises the path.
        Schema::connection('tenant')->table('general_charges', function ($table) {
            $table->json('loan_product_ids')->nullable()->after('credit_account_id');
        });
        DB::connection('tenant')->table('migrations')
            ->where('migration', '2026_05_14_000001_drop_loan_product_ids_from_general_charges')
            ->delete();
    }

    $income = ChartOfAccount::firstOrCreate(
        ['gl_code' => '42997'],
        [
            'name'            => 'Test Charge Income',
            'account_type'    => 'INCOME',
            'account_subtype' => 'Fee Income',
            'normal_balance'  => 'CR',
            'level'           => 3,
            'is_control'      => false,
            'is_postable'     => true,
            'is_active'       => true,
        ]
    );

    // Decorative row 1: application=on_loan_application
    $loanAppRow = GeneralCharge::create([
        'name'              => 'Loan Decorative',
        'is_revenue'        => true,
        'application'       => 'on_loan_application',
        'where_to_apply'    => 'loans',
        'charge_type'       => 'amount',
        'amount'            => 100,
        'credit_account_id' => $income->id,
        'is_active'         => true,
        'is_reversible'     => true,
    ]);

    // Decorative row 2: non-empty loan_product_ids JSON, application=other
    DB::connection('tenant')->table('general_charges')->insert([
        'name'              => 'Decorative LPIDs',
        'is_revenue'        => true,
        'application'       => 'other',
        'where_to_apply'    => 'loans',
        'charge_type'       => 'amount',
        'amount'            => 200,
        'credit_account_id' => $income->id,
        'loan_product_ids'  => json_encode([1, 2]),
        'is_active'         => true,
        'is_reversible'     => true,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    // Survivor row 2: explicit empty JSON array — JSON_LENGTH('[]')=0 → must NOT be deleted.
    DB::connection('tenant')->table('general_charges')->insert([
        'name'              => 'Survivor Empty JSON',
        'is_revenue'        => true,
        'application'       => 'other',
        'where_to_apply'    => 'savings',
        'charge_type'       => 'amount',
        'amount'            => 25,
        'credit_account_id' => $income->id,
        'loan_product_ids'  => json_encode([]),
        'is_active'         => true,
        'is_reversible'     => true,
        'created_at'        => now(),
        'updated_at'        => now(),
    ]);

    // Survivor row 1: a normal savings-event charge
    $survivor = GeneralCharge::create([
        'name'              => 'Survivor Savings',
        'is_revenue'        => true,
        'application'       => 'other',
        'where_to_apply'    => 'savings',
        'charge_type'       => 'amount',
        'amount'            => 50,
        'credit_account_id' => $income->id,
        'is_active'         => true,
        'is_reversible'     => true,
    ]);

    $this->artisan('migrate', [
        '--database' => 'tenant',
        '--path'     => 'database/migrations/tenant/2026_05_14_000001_drop_loan_product_ids_from_general_charges.php',
        '--force'    => true,
    ])->assertExitCode(0);

    expect(Schema::connection('tenant')->hasColumn('general_charges', 'loan_product_ids'))->toBeFalse();
    expect(GeneralCharge::find($loanAppRow->id))->toBeNull();
    expect(GeneralCharge::query()->where('name', 'Decorative LPIDs')->exists())->toBeFalse();
    expect(GeneralCharge::find($survivor->id))->not->toBeNull();
    expect(GeneralCharge::query()->where('name', 'Survivor Empty JSON')->exists())->toBeTrue();
});

it('rejects application=on_loan_application at validation', function () {
    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/general-charges', [
            'name'              => 'Bad Loan Charge',
            'is_revenue'        => 'yes',
            'application'       => 'on_loan_application',
            'charge_type'       => 'amount',
            'amount'            => 100,
            'credit_account_id' => $this->income->id,
        ]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('application');
});

it('rejects where_to_apply=loans at validation', function () {
    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/general-charges', [
            'name'              => 'Bad Loan Where',
            'is_revenue'        => 'yes',
            'application'       => 'other',
            'where_to_apply'    => 'loans',
            'charge_type'       => 'amount',
            'amount'            => 100,
            'credit_account_id' => $this->income->id,
        ]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('where_to_apply');
});

it('listing endpoint returns no loan_product_ids key and only-savings products string', function () {
    // Branch 1 (Head Office) is pre-seeded by TenantTestCase.
    // generalChargeList() filters by gc.branch_id, so the charge must carry branch_id=1
    // and we must POST branch_id=1 in the request body.
    $charge = GeneralCharge::forceCreate([
        'name'              => 'Survivor Two',
        'is_revenue'        => true,
        'application'       => 'other',
        'where_to_apply'    => 'savings',
        'charge_type'       => 'amount',
        'amount'            => 50,
        'credit_account_id' => $this->income->id,
        'is_active'         => true,
        'is_reversible'     => true,
        'branch_id'         => 1,
    ]);

    // The legacy settings route is registered as POST by routeListV2().
    // generalChargeList() is reached via:
    //   POST /api/v1/tenant/settings/general-charges/list
    //     → GeneralChargeController::get_general_charge_list()
    //       → GeneralChargeService::generalChargeList()
    // Response is wrapped by BaseController::Response() under the key "payload";
    // the method returns a paginator, so items live at payload.data.
    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/settings/general-charges/list', [
            'branch_id' => 1,
        ]);

    $response->assertOk();

    $items = collect($response->json('payload.data'));
    $row = $items->firstWhere('id', $charge->id);

    expect($row)->not->toBeNull()
        ->and($row)->toHaveKey('products')
        ->and($row)->not->toHaveKey('loan_product_ids');
});

it('listing endpoint exposes application so the frontend Applys column can render', function () {
    // Registration charges have application=on_registration and no where_to_apply.
    // The frontend Index.vue falls back to applicationLabel(item.application) when
    // item.charge_applys is null — but it needs the field to be in the response.
    $charge = GeneralCharge::forceCreate([
        'name'              => 'Registration Charge',
        'is_revenue'        => true,
        'application'       => 'on_registration',
        'where_to_apply'    => null,
        'charge_type'       => 'amount',
        'amount'            => 20000,
        'credit_account_id' => $this->income->id,
        'is_active'         => true,
        'is_reversible'     => true,
        'branch_id'         => 1,
    ]);

    $response = $this->actingAs($this->staff, 'tenant')
        ->postJson('/api/v1/tenant/settings/general-charges/list', [
            'branch_id' => 1,
        ]);

    $response->assertOk();

    $row = collect($response->json('payload.data'))->firstWhere('id', $charge->id);

    expect($row)->not->toBeNull()
        ->and($row['application'])->toBe('on_registration');
});
