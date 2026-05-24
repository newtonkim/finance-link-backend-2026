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
        if (Schema::connection('tenant')->hasTable('loan_documents')) {
            return;
        }

        Schema::connection('tenant')->dropIfExists('loan_documents');
        Schema::connection('tenant')->create('loan_documents', function (Blueprint $table) {
            $table->id();
            $table->enum('system_type', ['system', 'user_created'])->default('user_created')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('loan_id')->index();
            $table->string('document_type')->index();
            $table->string('file_path');
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->dateTime('uploaded_at')->index();
            $table->unsignedBigInteger('verified_by')->nullable()->index();
            $table->dateTime('verified_at')->nullable()->index();
            $table->boolean('is_mandatory')->default(false)->index();
            $table->timestamps();

            $table->foreign('loan_id')->references('id')->on('loans')->cascadeOnDelete();
            $table->foreign('uploaded_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('verified_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_documents');
    }
};
