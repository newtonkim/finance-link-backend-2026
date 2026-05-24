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
        if (Schema::connection('tenant')->hasTable('public_holidays')) {
            return;
        }

        Schema::connection('tenant')->create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('holiday_date')->index();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('loan_repayment_schedule_id')->nullable()->index();
            $table->unsignedBigInteger('loan_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('recurrence_type')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('loan_repayment_schedule_id')->references('id')->on('loan_repayment_schedule')->nullOnDelete();
            $table->foreign('loan_id')->references('id')->on('loans')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('public_holidays');
    }
};
