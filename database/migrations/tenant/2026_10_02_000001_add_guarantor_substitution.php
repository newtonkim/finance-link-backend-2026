<?php

use App\Tenant\Settings\GuarantorSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replacing a guarantor partway through a loan, a guarantor asking to be replaced,
 * and telling guarantors when their loan is rescheduled.
 *
 * substitutes_id: on a new guarantee, the one it is taking over from.
 * substituted_by_id: on the old guarantee, the one that took over.
 */
return new class extends Migration
{
    private const ADDED_SETTINGS = ['sacco-guarantor-topup-needs-guarantors'];

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('loan_application_guarantors', 'substitutes_id')) {
            Schema::connection('tenant')->table('loan_application_guarantors', function (Blueprint $table) {
                $table->unsignedBigInteger('substitutes_id')->nullable()->after('loan_id')->index();
                $table->unsignedBigInteger('substituted_by_id')->nullable()->after('substitutes_id');
                $table->timestamp('release_requested_at')->nullable()->after('arrears_notice_count');
                $table->text('release_request_reason')->nullable()->after('release_requested_at');
                $table->timestamp('reschedule_notified_at')->nullable()->after('release_request_reason');
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
            $table->dropIndex(['substitutes_id']);
            $table->dropColumn([
                'substitutes_id',
                'substituted_by_id',
                'release_requested_at',
                'release_request_reason',
                'reschedule_notified_at',
            ]);
        });
    }
};
