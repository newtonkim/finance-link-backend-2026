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
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loans', 'loan_purpose')) {
                $table->text('loan_purpose')->nullable()->after('repayment_cycle');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loans', 'loan_purpose')) {
                $table->dropColumn('loan_purpose');
            }
        });
    }
};
