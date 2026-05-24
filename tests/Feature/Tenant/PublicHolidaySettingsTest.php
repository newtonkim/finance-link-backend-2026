<?php

namespace Tests\Feature\Tenant;

use App\Models\Staff;
use App\Tenant\Modules\Settings\Models\LoanSetting;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

class PublicHolidaySettingsTest extends TenantTestCase
{
    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = Staff::create([
            'name' => 'Holiday Settings Admin',
            'email' => 'holiday-settings-admin@test.com',
            'password' => Hash::make('password'),
            'role' => 'Admin',
            'branch_id' => 1,
            'is_tenant_admin' => true,
        ]);

        $this->actingAs($this->staff, 'tenant');
    }

    public function test_index_returns_all_holiday_scheduling_flags(): void
    {
        LoanSetting::currentForBranch(1)->update([
            'push_installments_on_holidays' => true,
            'push_installments_on_holidays_weekdays_only' => false,
            'relative_scheduling' => true,
        ]);

        $response = $this->getJson('/api/v1/tenant/public-holidays');

        $response->assertOk()
            ->assertJsonPath('settings.push_installments_on_holidays', true)
            ->assertJsonPath('settings.push_installments_on_holidays_weekdays_only', false)
            ->assertJsonPath('settings.relative_scheduling', true);
    }

    public function test_update_settings_persists_all_holiday_scheduling_flags(): void
    {
        $response = $this->putJson('/api/v1/tenant/public-holidays/settings', [
            'push_installments_on_holidays' => true,
            'push_installments_on_holidays_weekdays_only' => true,
            'relative_scheduling' => true,
        ]);

        $response->assertOk();

        $settings = LoanSetting::currentForBranch(1)->fresh();

        $this->assertTrue((bool) $settings->push_installments_on_holidays);
        $this->assertTrue((bool) $settings->push_installments_on_holidays_weekdays_only);
        $this->assertTrue((bool) $settings->relative_scheduling);
    }
}
