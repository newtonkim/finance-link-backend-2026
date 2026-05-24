<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('loan_rescheduling', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'old_status')) {
                $table->string('old_status', 50)->nullable()->after('reschedule_type')
                    ->comment('Loan status at the time of rescheduling (disbursed, active, arrears)');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_rescheduling', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_rescheduling', 'old_status')) {
                $table->dropColumn('old_status');
            }
        });
    }
};
