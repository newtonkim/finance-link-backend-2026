<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_documents');
    }

    public function down(): void
    {
        // Legacy table intentionally not recreated. Application-stage documents
        // now live in loan_application_documents.
    }
};
