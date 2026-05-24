<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('loan_application_documents')) {
            return;
        }

        Schema::connection('tenant')->create('loan_application_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_application_id')->index();
            $table->string('document_type')->index();
            $table->string('original_name');
            $table->string('file_path')->index();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable()->comment('Bytes');
            $table->string('status')->default('pending')->index()
                ->comment('pending | verified | rejected');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->foreign('loan_application_id')
                ->references('id')->on('loan_applications')->cascadeOnDelete();
            $table->foreign('verified_by')
                ->references('id')->on('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_application_documents');
    }
};
