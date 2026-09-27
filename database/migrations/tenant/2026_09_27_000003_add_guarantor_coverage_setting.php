<?php

use App\Tenant\Settings\GuarantorSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills guarantor settings added to GuarantorSettings after the module was first
 * seeded. Like the original backfill, each row is checked by name first, so rows that
 * already exist are left untouched and the migration is safe to re-run.
 */
return new class extends Migration
{
    private const ADDED = ['sacco-guarantor-required-coverage-percentage'];

    public function up(): void
    {
        foreach (GuarantorSettings::definitions() as $definition) {
            if (! in_array($definition['settings_name'], self::ADDED, true)) {
                continue;
            }

            $exists = DB::table('system_settings')
                ->where('settings_name', $definition['settings_name'])
                ->exists();

            if ($exists) {
                continue;
            }

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

    public function down(): void
    {
        DB::table('system_settings')->whereIn('settings_name', self::ADDED)->delete();
    }
};
