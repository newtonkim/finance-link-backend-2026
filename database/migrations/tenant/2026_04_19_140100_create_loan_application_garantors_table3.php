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
        if (Schema::connection('tenant')->hasTable('loan_application_guarantors')) {
            return;
        }

        Schema::create('loan_application_guarantors', function (Blueprint $table) {
            $table->id();

            $table->string('code', 255)->nullable()->index();
            $table->unsignedBigInteger('guarantor_id')->index()->comment('ID of guarantor');
            $table->enum('guarantor_type', ['staff', 'individual', 'group'])->index()->comment('Type of the guarantor');
            $table->decimal('guarantee_amount', 15, 2)->default(0);
            $table->decimal('max_guarantee_used', 15, 2)->default(0);
            $table->unsignedBigInteger('loan_application_id')->index();
            $table->text('note')->nullable();
            $table->enum('system_type', ['system', 'user_created'])->default('user_created')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->date('accepted_date')->nullable()->index();
            $table->date('released_date')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('loan_application_id')->references('id')->on('loan_applications')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('staff')->nullOnDelete();

            $table->unique(['loan_application_id', 'guarantor_id', 'guarantor_type'], 'unique_loan_guarantor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_application_guarantors');
    }
};
