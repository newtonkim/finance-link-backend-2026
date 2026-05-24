<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('loan_topup_applications', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_topup_applications', 'loan_application_id')) {
                $table->unsignedBigInteger('loan_application_id')->nullable()->after('new_loan_id');
            }

            // Change status from enum to string to allow more flexible states like 'application_created'
            $table->string('status')->default('draft')->change();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_topup_applications', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_topup_applications', 'loan_application_id')) {
                $table->dropColumn('loan_application_id');
            }
        });
    }
};
