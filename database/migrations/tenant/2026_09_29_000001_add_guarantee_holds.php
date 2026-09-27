<?php

use App\Tenant\Settings\GuarantorSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links each guarantee to the loan it ends up standing behind, and records when it
 * was locked (at disbursement) and released (when the loan closes).
 *
 * Guarantees on loans that were disbursed before this existed are backfilled the
 * same way, so their guarantors' savings are held from now on and guarantees on
 * already-closed loans show as released.
 */
return new class extends Migration
{
    private const ADDED_SETTINGS = ['sacco-guarantor-hold-savings'];

    public function up(): void
    {
        $schema = Schema::connection('tenant');

        if (! $schema->hasColumn('loan_application_guarantors', 'loan_id')) {
            $schema->table('loan_application_guarantors', function (Blueprint $table) {
                $table->unsignedBigInteger('loan_id')->nullable()->after('loan_application_id')->index();
                $table->timestamp('locked_at')->nullable()->after('consent_document_path');
                $table->timestamp('released_at')->nullable()->after('locked_at');
                $table->string('release_reason', 50)->nullable()->after('released_at');
            });
        }

        $this->backfill();

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

    private function backfill(): void
    {
        $db = DB::connection('tenant');

        $pledges = $db->table('loan_application_guarantors as g')
            ->join('loan_applications as la', 'la.id', '=', 'g.loan_application_id')
            ->join('loans as l', 'l.id', '=', 'la.disbursed_loan_id')
            ->whereNull('g.deleted_at')
            ->whereNull('g.loan_id')
            ->whereIn('g.status', ['proposed', 'requested', 'accepted'])
            ->get(['g.id', 'g.status', 'l.id as loan_id', 'l.status as loan_status', 'l.created_at as loan_created_at']);

        foreach ($pledges as $pledge) {
            $closed = $pledge->loan_status === 'closed';

            $db->table('loan_application_guarantors')->where('id', $pledge->id)->update([
                'loan_id' => $pledge->loan_id,
                'status' => $closed ? 'released' : 'locked',
                'status_changed_at' => now(),
                'locked_at' => $pledge->loan_created_at,
                'released_at' => $closed ? now() : null,
                'released_date' => $closed ? now()->toDateString() : null,
                'release_reason' => $closed ? 'loan_closed' : null,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('settings_name', self::ADDED_SETTINGS)->delete();

        $db = DB::connection('tenant');
        $db->table('loan_application_guarantors')->whereIn('status', ['locked', 'released'])->update(['status' => 'accepted']);

        Schema::connection('tenant')->table('loan_application_guarantors', function (Blueprint $table) {
            $table->dropIndex(['loan_id']);
            $table->dropColumn(['loan_id', 'locked_at', 'released_at', 'release_reason']);
        });
    }
};
