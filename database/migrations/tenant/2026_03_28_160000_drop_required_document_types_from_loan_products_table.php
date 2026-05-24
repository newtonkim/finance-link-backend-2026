<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_products', 'required_document_types')) {
                $table->dropColumn('required_document_types');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_products', 'required_document_types')) {
                $table->jsonb('required_document_types')->nullable()->after('arrears_action');
            }
        });
    }
};
