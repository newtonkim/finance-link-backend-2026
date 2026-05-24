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
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_products', 'loan_product_id')) {
                $table->dropColumn('loan_product_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_products', 'loan_product_id')) {
                $table->string('loan_product_id')->nullable()->index();
            }
        });
    }
};
