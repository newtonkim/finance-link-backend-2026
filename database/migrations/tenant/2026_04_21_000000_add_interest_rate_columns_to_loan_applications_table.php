<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('tenant')->table('loan_applications', function (Blueprint $table) {
            $table->decimal('recommended_interest_rate', 5, 2)->nullable()->after('recommended_term');
            $table->decimal('approved_interest_rate', 5, 2)->nullable()->after('approved_term');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loan_applications', function (Blueprint $table) {
            $table->dropColumn(['recommended_interest_rate', 'approved_interest_rate']);
        });
    }
};
