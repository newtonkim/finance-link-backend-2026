<?php

use App\Tenant\Settings\GuarantorSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records when each guarantor was last warned that the loan they guarantee is
 * overdue, so a warning goes out once per spell of arrears (and then only as often
 * as the reminder setting allows), and seeds the two arrears-warning settings.
 */
return new class extends Migration
{
    private const ADDED_SETTINGS = [
        'sacco-guarantor-arrears-notice-days',
        'sacco-guarantor-arrears-reminder-days',
    ];

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('loan_application_guarantors', 'arrears_notified_at')) {
            Schema::connection('tenant')->table('loan_application_guarantors', function (Blueprint $table) {
                $table->timestamp('arrears_notified_at')->nullable()->after('release_reason');
                $table->unsignedSmallInteger('arrears_notice_count')->default(0)->after('arrears_notified_at');
            });
        }

        foreach (GuarantorSettings::definitions() as $definition) {
            if (! in_array($definition['settings_name'], self::ADDED_SETTINGS, true)) {
                continue;
            }

            if (DB::table('system_settings')->where('settings_name', $definition['settings_name'])->exists()) {
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
        DB::table('system_settings')->whereIn('settings_name', self::ADDED_SETTINGS)->delete();

        Schema::connection('tenant')->table('loan_application_guarantors', function (Blueprint $table) {
            $table->dropColumn(['arrears_notified_at', 'arrears_notice_count']);
        });
    }
};
