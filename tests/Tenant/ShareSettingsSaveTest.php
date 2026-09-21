<?php

use App\Models\Staff;
use App\Tenant\Services\TenantSettingUpdateOrCreateService;
use Illuminate\Support\Facades\DB;

/**
 * Saving a share setting used to run the share-price recalculation regardless of
 * which setting was being saved, and dereferenced the latest share_capitalization
 * row without checking one existed. On a tenant with no capitalisation rows —
 * which is every newly provisioned tenant — toggling any share switch returned a
 * 500 and the setting was never written.
 */
beforeEach(function () {
    $this->staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->staff, 'sanctum');

    DB::table('share_capitalization')->delete();
    DB::table('system_settings')->whereIn('settings_name', [
        'sacco-share-price-value',
        'sacco-share-hide-shareholder-field',
    ])->delete();

    $this->priceId = DB::table('system_settings')->insertGetId([
        'settings_name' => 'sacco-share-price-value',
        'settings_action' => json_encode(['attr' => 'number', 'action' => 12]),
        'created_by' => $this->staff->id,
        'updated_by' => $this->staff->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->toggleId = DB::table('system_settings')->insertGetId([
        'settings_name' => 'sacco-share-hide-shareholder-field',
        'settings_action' => json_encode(['attr' => 'switch', 'action' => 0]),
        'created_by' => $this->staff->id,
        'updated_by' => $this->staff->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function saveShareSetting(int $id, array $settingsAction): void
{
    request()->replace(['id' => $id, 'settings_action' => $settingsAction]);
    app(TenantSettingUpdateOrCreateService::class)->saveChangedSettingsShare();
}

function storedAction(int $id): array
{
    $row = DB::table('system_settings')->where('id', $id)->first(['settings_action']);

    return json_decode($row->settings_action, true);
}

it('persists a switch setting when there is no share capitalisation row', function () {
    // The 500: share_capitalization is empty on every freshly provisioned tenant.
    expect(DB::table('share_capitalization')->count())->toBe(0);

    saveShareSetting($this->toggleId, ['attr' => 'switch', 'action' => 1]);

    expect(storedAction($this->toggleId)['action'])->toBe(1);
});

it('toggles the switch back off again', function () {
    saveShareSetting($this->toggleId, ['attr' => 'switch', 'action' => 1]);
    expect(storedAction($this->toggleId)['action'])->toBe(1);

    saveShareSetting($this->toggleId, ['attr' => 'switch', 'action' => 0]);
    expect(storedAction($this->toggleId)['action'])->toBe(0);
});

it('never writes a switch value into the share price', function () {
    // Saving the hide-shareholder toggle previously fed its 1/0 into share_price.
    $capitalId = DB::table('share_capitalization')->insertGetId([
        'open_capital' => 1000,
        'share_price' => 12,
        'open_capital_points_walth_amount' => 12000,
        'opening_balance' => 0,
        'created_by' => $this->staff->id,
        'updated_by' => $this->staff->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    saveShareSetting($this->toggleId, ['attr' => 'switch', 'action' => 1]);

    $capital = DB::table('share_capitalization')->where('id', $capitalId)->first();
    expect((float) $capital->share_price)->toBe(12.0)
        ->and((float) $capital->open_capital_points_walth_amount)->toBe(12000.0);
});

it('recalculates capitalisation when the share price itself is saved', function () {
    $capitalId = DB::table('share_capitalization')->insertGetId([
        'open_capital' => 1000,
        'share_price' => 12,
        'open_capital_points_walth_amount' => 12000,
        'opening_balance' => 0,
        'created_by' => $this->staff->id,
        'updated_by' => $this->staff->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    saveShareSetting($this->priceId, ['attr' => 'number', 'action' => 20]);

    $capital = DB::table('share_capitalization')->where('id', $capitalId)->first();
    expect((float) $capital->share_price)->toBe(20.0)
        ->and((float) $capital->open_capital_points_walth_amount)->toBe(20000.0);

    expect(storedAction($this->priceId)['action'])->toBe(20);
});

it('saves the share price even when there is no capitalisation row to update', function () {
    expect(DB::table('share_capitalization')->count())->toBe(0);

    saveShareSetting($this->priceId, ['attr' => 'number', 'action' => 25]);

    expect(storedAction($this->priceId)['action'])->toBe(25);
});
