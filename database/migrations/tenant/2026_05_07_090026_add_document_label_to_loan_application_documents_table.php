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
        Schema::connection('tenant')->table('loan_application_documents', function (Blueprint $table) {
            $table->string('document_label')->nullable()->after('document_type');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_application_documents', function (Blueprint $table) {
            $table->dropColumn('document_label');
        });
    }
};
