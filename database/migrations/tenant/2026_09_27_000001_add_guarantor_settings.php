<?php

use App\Tenant\Settings\GuarantorSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the guarantor settings module into tenants that were provisioned before
 * it existed. New tenants get the same rows from SaccoSettingsSeeder.
 *
 * GuarantorSettings::restoreMissing() checks each row by name before inserting, so
 * the migration is safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        GuarantorSettings::restoreMissing();
    }

    public function down(): void
    {
        DB::table('system_settings')
            ->where('settings_module', GuarantorSettings::MODULE)
            ->delete();
    }
};
