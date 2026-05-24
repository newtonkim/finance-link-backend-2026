<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('loan_applications', function (Blueprint $table) {
            // Officer notes (appraisal notes by loan officer)
            $table->text('officer_notes')->nullable()->after('appraisal_notes');

            // Branch Manager notes
            $table->text('bm_notes')->nullable()->after('officer_notes');

            // Correction reason (BM reason when returning for correction)
            $table->text('correction_reason')->nullable()->after('bm_notes');

            // Quorum snapshot (set when committee voting begins)
            $table->unsignedInteger('quorum_required')->nullable()->after('correction_reason')->index();
            $table->unsignedInteger('approval_threshold')->nullable()->after('quorum_required')->index();
            $table->boolean('unanimity_required')->default(false)->after('approval_threshold')->index();

            // Final approved terms (committee override, defaults to recommended)
            $table->decimal('final_approved_amount', 15, 2)->nullable()->after('approved_amount');
            $table->unsignedSmallInteger('final_approved_term')->nullable()->after('final_approved_amount')->index();

            // Proposed start date (locked at Confirm Terms)
            $table->date('proposed_start_date')->nullable()->after('final_approved_term')->index();

            // Schedule lock timestamp
            $table->timestamp('schedule_locked_at')->nullable()->after('proposed_start_date')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_applications', function (Blueprint $table) {
            $table->dropColumn([
                'officer_notes',
                'bm_notes',
                'correction_reason',
                'quorum_required',
                'approval_threshold',
                'unanimity_required',
                'final_approved_amount',
                'final_approved_term',
                'proposed_start_date',
                'schedule_locked_at',
            ]);
        });
    }
};
