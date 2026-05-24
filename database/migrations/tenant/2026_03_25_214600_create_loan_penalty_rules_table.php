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
        if (Schema::connection('tenant')->hasTable('loan_penalty_rules')) {
            return;
        }

        Schema::connection('tenant')->create('loan_penalty_rules', function (Blueprint $table) {
            $table->id();
            $table->enum('system_type', ['system', 'user_created'])->default('user_created')->index();
            $table->unsignedBigInteger('loan_product_id')->index();
            $table->string('penalty_type')->nullable()->index();
            $table->decimal('penalty_rate', 15, 2)->default(0);
            $table->integer('grace_days')->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('applies_to')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('loan_product_id')->references('id')->on('loan_products')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_penalty_rules');
    }
};
