<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('journal_type')->after('entry_no'); // DISBURSEMENT, REPAYMENT, etc.
            $table->string('reference_type')->nullable()->after('journal_type');
            $table->uuid('reference_id')->nullable()->after('reference_type');
            $table->char('fiscal_period', 7)->after('date'); // YYYY-MM
            $table->boolean('is_system')->default(false)->after('narration');

            // Rename 'date' to 'period_date' to match the guide if preferred,
            // but keeping 'date' as it's common. Let's add 'period_date' for clarity.
            $table->date('period_date')->nullable()->after('date');
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->uuid('member_id')->nullable()->after('narration');
            $table->uuid('loan_id')->nullable()->after('member_id');
            $table->uuid('savings_id')->nullable()->after('loan_id');
            $table->string('cost_centre')->nullable()->after('savings_id');
            $table->smallInteger('line_no')->default(1)->after('cost_centre');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn([
                'journal_type',
                'reference_type',
                'reference_id',
                'fiscal_period',
                'is_system',
                'period_date',
            ]);
        });

        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropColumn([
                'member_id',
                'loan_id',
                'savings_id',
                'cost_centre',
                'line_no',
            ]);
        });
    }
};
