<?php

use App\Models\Staff;
use App\Tenant\Services\TenantSettingService;
use App\Tenant\Services\TenantSettingUpdateOrCreateService;
use App\Tenant\Settings\GuarantorSettings;
use Illuminate\Support\Facades\DB;

/**
 * The guarantor module is defined once in GuarantorSettings and consumed by both the
 * seeder (new tenants) and the backfill migration (existing ones), so these assert
 * against that definition rather than a hardcoded list — a setting added there has
 * to appear on the settings page without anyone remembering to update a second copy.
 */
beforeEach(function () {
    $this->staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->staff, 'sanctum');

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
});

it('lists every guarantor setting that is defined', function () {
    $list = app(TenantSettingService::class)->guarantorSettingsList();

    foreach (GuarantorSettings::definitions() as $definition) {
        expect($list)->toHaveKey($definition['settings_name']);
    }
});

it('returns only guarantor settings, not settings from other modules', function () {
    $list = app(TenantSettingService::class)->guarantorSettingsList();

    $names = array_keys($list);
    expect($names)->not->toBeEmpty();

    // SettingsListPreparation folds in the 'all-system' module for every page, so a
    // returned row is legitimate only if it belongs to guarantor or all-system.
    // Asserting per row keeps this meaningful even when nothing unexpected leaks in.
    $modules = DB::table('system_settings')
        ->whereIn('settings_name', $names)
        ->pluck('settings_module', 'settings_name');

    foreach ($names as $name) {
        expect($modules[$name])->toBeIn([GuarantorSettings::MODULE, 'all-system']);
    }
});

it('carries a human readable description for every setting', function () {
    $list = app(TenantSettingService::class)->guarantorSettingsList();

    foreach (GuarantorSettings::definitions() as $definition) {
        expect($list[$definition['settings_name']]['description'])->not->toBeEmpty();
    }
});

it('persists a change to a guarantor setting', function () {
    $row = DB::table('system_settings')
        ->where('settings_name', 'sacco-guarantor-minimum-number')
        ->first(['id']);

    request()->replace([
        'id' => $row->id,
        'settings_action' => ['attr' => 'number', 'action' => 2],
    ]);

    app(TenantSettingUpdateOrCreateService::class)->saveChangedSettings();

    $stored = json_decode(
        DB::table('system_settings')->where('id', $row->id)->value('settings_action'),
        true,
    );

    expect($stored['action'])->toBe(2);
});
