<?php

use App\Tenant\Settings\GuarantorSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the guarantor settings module into tenants that were provisioned before
 * it existed. New tenants get the same rows from SaccoSettingsSeeder.
 *
 * system_settings has no unique index on settings_name, so insertOrIgnore would not
 * protect against duplicates here. Each row is checked by name before insert, which
 * also makes the migration safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (GuarantorSettings::definitions() as $definition) {
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
                // created_by and updated_by are NOT NULL with no default. Existing
                // system-seeded rows carry 0, so these match rather than inventing a
                // staff id for rows nobody created.
                'created_by' => 0,
                'updated_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('settings_module', GuarantorSettings::MODULE)
            ->delete();
    }
};
