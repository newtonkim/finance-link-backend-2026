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
        if (Schema::connection('tenant')->hasTable('loan_application_status_history')) {
            return;
        }

        Schema::connection('tenant')->create('loan_application_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_application_id')->index();
            $table->string('from_status')->nullable()->index();
            $table->string('to_status')->index();
            $table->unsignedBigInteger('changed_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->string('ip_address')->nullable()->index();
            $table->timestamp('changed_at')->index();
            $table->timestamps();

            $table->foreign('loan_application_id')->references('id')->on('loan_applications')->cascadeOnDelete();
            $table->foreign('changed_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_application_status_history');
    }
};
