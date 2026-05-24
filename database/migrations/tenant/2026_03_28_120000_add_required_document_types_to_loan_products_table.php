<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->jsonb('required_document_types')->nullable()->after('arrears_action')
                ->comment('Array of document type slugs required for loan applications under this product');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->dropColumn('required_document_types');
        });
    }
};
