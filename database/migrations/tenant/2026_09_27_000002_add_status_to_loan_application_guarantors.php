<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives each guarantee a lifecycle status. Rows that existed before this were saved
 * with no consent step, so they are backfilled as accepted rather than proposed —
 * otherwise every in-flight application would suddenly look under-guaranteed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('loan_application_guarantors', 'status')) {
            return;
        }

        Schema::connection('tenant')->table('loan_application_guarantors', function (Blueprint $table) {
            $table->string('status', 30)->default('proposed')->after('guarantee_amount')->index();
            $table->timestamp('status_changed_at')->nullable()->after('status');
        });

        DB::connection('tenant')->table('loan_application_guarantors')->update([
            'status' => 'accepted',
            'status_changed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_application_guarantors', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'status_changed_at']);
        });
    }
};
