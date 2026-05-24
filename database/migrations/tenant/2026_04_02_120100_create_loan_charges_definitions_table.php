<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('loan_charges')) {
            return;
        }

        Schema::connection('tenant')->create('loan_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->enum('category', ['processing_fee', 'penalty', 'late_fee', 'appraisal_fee', 'insurance', 'other']);
            $table->enum('charge_type', ['flat', 'percentage']);
            $table->decimal('value', 15, 2)->default(0);
            $table->enum('frequency', ['one_time', 'daily', 'weekly', 'monthly'])->default('one_time');
            $table->unsignedInteger('grace_days')->default(0);
            $table->decimal('max_value', 15, 2)->nullable();
            $table->enum('max_value_type', ['none', 'flat_cap', 'percentage_of_outstanding'])->default('none');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('income_account_id')->nullable();
            $table->unsignedBigInteger('receivable_account_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('category');
            $table->index('is_active');
            $table->index('code');

            $table->foreign('income_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
            $table->foreign('receivable_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_charges');
    }
};
