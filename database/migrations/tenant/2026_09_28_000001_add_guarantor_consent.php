<?php

use App\Tenant\Settings\GuarantorSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guarantor consent: the columns that record a request and the guarantor's answer,
 * and the two settings that switch consent on and set how long a request stays open.
 * Settings rows are checked by name first, so the migration is safe to re-run.
 */
return new class extends Migration
{
    private const ADDED_SETTINGS = [
        'sacco-guarantor-consent-required',
        'sacco-guarantor-consent-expiry-days',
    ];

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('loan_application_guarantors', 'requested_at')) {
            Schema::connection('tenant')->table('loan_application_guarantors', function (Blueprint $table) {
                $table->timestamp('requested_at')->nullable()->after('status_changed_at');
                $table->timestamp('consent_expires_at')->nullable()->after('requested_at')->index();
                $table->timestamp('responded_at')->nullable()->after('consent_expires_at');
                // member_portal when the guarantor answered themselves, officer when staff recorded it.
                $table->string('response_channel', 20)->nullable()->after('responded_at');
                $table->unsignedBigInteger('responded_by')->nullable()->after('response_channel');
                $table->text('decline_reason')->nullable()->after('responded_by');
                $table->string('consent_document_path')->nullable()->after('decline_reason');
            });
        }

        foreach (GuarantorSettings::definitions() as $definition) {
            if (! in_array($definition['settings_name'], self::ADDED_SETTINGS, true)) {
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
        DB::table('system_settings')->whereIn('settings_name', self::ADDED_SETTINGS)->delete();

        Schema::connection('tenant')->table('loan_application_guarantors', function (Blueprint $table) {
            $table->dropIndex(['consent_expires_at']);
            $table->dropColumn([
                'requested_at',
                'consent_expires_at',
                'responded_at',
                'response_channel',
                'responded_by',
                'decline_reason',
                'consent_document_path',
            ]);
        });
    }
};
