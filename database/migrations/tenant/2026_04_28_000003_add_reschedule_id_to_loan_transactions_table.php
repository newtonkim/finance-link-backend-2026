<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('loan_transactions', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_transactions', 'reschedule_id')) {
                $table->unsignedBigInteger('reschedule_id')->nullable()->after('loan_id')
                    ->comment('References loan_rescheduling.id; set when payment is made on a rescheduled loan');

                $table->foreign('reschedule_id')
                    ->references('id')
                    ->on('loan_rescheduling')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_transactions', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_transactions', 'reschedule_id')) {
                $table->dropForeign(['reschedule_id']);
                $table->dropColumn('reschedule_id');
            }
        });
    }
};
