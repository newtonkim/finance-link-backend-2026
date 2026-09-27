<?php

/**
 * Shared fixtures for the guarantor tests. Loaded with require_once from each
 * guarantor test file, since Pest test files cannot share functions otherwise.
 */

use App\Models\Member;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Settings\GuarantorSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Seeds every guarantor setting at its shipped default. */
function seedGuarantorSettings(): void
{
    DB::table('system_settings')->where('settings_module', GuarantorSettings::MODULE)->delete();

    foreach (GuarantorSettings::definitions() as $definition) {
        DB::table('system_settings')->insert([
            'settings_name' => $definition['settings_name'],
            'settings_module' => GuarantorSettings::MODULE,
            'settings_status' => 'active',
            'settings_action' => json_encode($definition['settings_action']),
            'settings_action_description' => $definition['settings_action_description'],
            'settings_setting_description' => $definition['settings_setting_description'],
            'system_type' => 'system',
            'created_by' => 0,
            'updated_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/** Sets one guarantor setting's value, leaving the others at their defaults. */
function setGuarantorSetting(string $name, $value): void
{
    DB::table('system_settings')->where('settings_name', $name)->update([
        'settings_action' => json_encode(['action' => $value, 'attr' => 'number']),
    ]);
}

function memberWithSavings(float $balance, array $attributes = []): Member
{
    $member = Member::factory()->create($attributes);
    SavingsAccount::factory()->create(['member_id' => $member->id, 'balance' => $balance]);

    return $member;
}

function guarantorApplication(array $attributes = [], array $product = []): LoanApplication
{
    $loanProduct = LoanProduct::create(['name' => 'Test Loan '.uniqid(), ...$product]);

    return LoanApplication::create([
        'application_no' => 'LA-'.uniqid(),
        'member_id' => memberWithSavings(0)->id,
        'loan_product_id' => $loanProduct->id,
        'requested_amount' => 1000,
        'requested_term' => 12,
        'purpose' => 'Stock',
        'status' => LoanApplication::STATUS_DRAFT,
        ...$attributes,
    ]);
}

function pledge(LoanApplication $application, Member|SavingsGroup $guarantor, float $amount): LoanApplicationGuarantor
{
    return app(LoanGuarantorServiceInterface::class)->addGuarantor(
        application: $application,
        type: $guarantor instanceof SavingsGroup ? 'group' : 'individual',
        guarantorId: $guarantor->id,
        accountId: null,
        amount: $amount,
        note: null,
        actorId: null,
    );
}

function validationErrors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    throw new RuntimeException('Expected a ValidationException.');
}
