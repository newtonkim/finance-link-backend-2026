<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_membership_months')->default(0)->after('min_guarantors')
                ->comment('Minimum months of membership before eligibility');
            $table->decimal('exposure_limit', 15, 2)->nullable()->after('max_amount')
                ->comment('Maximum total outstanding loan exposure per member for this product');
            $table->string('arrears_action')->default('warn')->after('exposure_limit')
                ->comment('block = hard-fail on arrears, warn = flag only');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->dropColumn(['min_membership_months', 'exposure_limit', 'arrears_action']);
        });
    }
};
