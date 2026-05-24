<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('loan_product_required_documents')) {
            return;
        }

        Schema::connection('tenant')->create('loan_product_required_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_product_id')->index();
            $table->unsignedBigInteger('document_type_id')->index();
            $table->boolean('is_required')->default(true)->index();
            $table->string('required_stage')->default('submission')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->auditColumns();
            $table->timestamps();

            $table->unique(
                ['loan_product_id', 'document_type_id', 'required_stage'],
                'loan_product_required_docs_unique'
            );

            $table->foreign('loan_product_id')
                ->references('id')->on('loan_products')
                ->cascadeOnDelete();

            $table->foreign('document_type_id')
                ->references('id')->on('document_types')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_product_required_documents');
    }
};
