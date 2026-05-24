<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('loan_rescheduling', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'fees_applied')) {
                $table->json('fees_applied')->nullable()->after('interest_waived');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'old_product_id')) {
                $table->unsignedBigInteger('old_product_id')->nullable()->after('fees_applied');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_rescheduling', 'new_product_id')) {
                $table->unsignedBigInteger('new_product_id')->nullable()->after('old_product_id');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_rescheduling', function (Blueprint $table) {
            $columns = ['fees_applied', 'old_product_id', 'new_product_id'];
            $existing = array_filter($columns, fn ($col) => Schema::connection('tenant')->hasColumn('loan_rescheduling', $col));
            if (! empty($existing)) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
