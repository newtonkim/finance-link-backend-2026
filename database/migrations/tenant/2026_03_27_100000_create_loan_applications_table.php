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
        if (Schema::connection('tenant')->hasTable('loan_applications')) {
            return;
        }

        Schema::connection('tenant')->create('loan_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_no')->unique()->index();
            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('loan_product_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('loan_officer_id')->nullable()->index();
            $table->decimal('requested_amount', 15, 2);
            $table->unsignedSmallInteger('requested_term');
            $table->string('purpose')->nullable();
            $table->string('repayment_source')->nullable();
            $table->string('status')->default('draft')->index();
            $table->decimal('recommended_amount', 15, 2)->nullable();
            $table->unsignedSmallInteger('recommended_term')->nullable();
            $table->decimal('approved_amount', 15, 2)->nullable();
            $table->unsignedSmallInteger('approved_term')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('appraisal_notes')->nullable();
            $table->text('approval_notes')->nullable();
            $table->unsignedBigInteger('appraised_by')->nullable()->index();
            $table->unsignedBigInteger('recommended_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->unsignedBigInteger('rejected_by')->nullable()->index();
            $table->unsignedBigInteger('disbursed_loan_id')->nullable()->index();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('recommended_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->auditColumns();
            $table->timestamp('created_at')->useCurrent()->nullable()->index();
            $table->timestamp('updated_at')->useCurrent()->nullable()->index();
            $table->softDeletes();

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('loan_product_id')->references('id')->on('loan_products')->restrictOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('loan_officer_id')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('appraised_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('recommended_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('disbursed_loan_id')->references('id')->on('loans')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_applications');
    }
};
