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
        if (Schema::connection('tenant')->hasTable('loan_rescheduling')) {
            return;
        }

        Schema::connection('tenant')->create('loan_rescheduling', function (Blueprint $table) {
            $table->id();
            $table->string('reschedule_id')->nullable()->index();
            $table->unsignedBigInteger('original_loan_id')->index();
            $table->unsignedBigInteger('new_loan_id')->nullable()->index();
            $table->date('reschedule_date')->nullable()->index();
            $table->decimal('old_outstanding', 15, 2)->default(0);
            $table->decimal('new_principal', 15, 2)->default(0);
            $table->decimal('new_rate', 15, 2)->default(0);
            $table->integer('new_duration')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('original_loan_id')->references('id')->on('loans')->cascadeOnDelete();
            $table->foreign('new_loan_id')->references('id')->on('loans')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_rescheduling');
    }
};
