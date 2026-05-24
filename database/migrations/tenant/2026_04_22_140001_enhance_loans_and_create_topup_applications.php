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
        // 1. Enhance Loans table
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loans', 'parent_loan_id')) {
                $table->unsignedBigInteger('parent_loan_id')->nullable()->after('id');
                $table->foreign('parent_loan_id')->references('id')->on('loans')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'topup_type')) {
                $table->enum('topup_type', ['consolidated', 'parallel', 'none'])->default('none')->after('parent_loan_id');
            }
        });

        // 2. Create Top-Up Applications table
        if (Schema::connection('tenant')->hasTable('loan_topup_applications')) {
            return;
        }
        Schema::connection('tenant')->create('loan_topup_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_number')->unique();
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('reference_loan_id');
            $table->unsignedBigInteger('new_loan_id')->nullable();
            $table->enum('topup_type', ['consolidated', 'parallel']);
            $table->decimal('topup_amount', 15, 2);
            $table->decimal('new_loan_total', 15, 2);
            $table->integer('requested_term');
            $table->decimal('new_monthly_installment', 15, 2);
            $table->decimal('dsr_before', 5, 4)->nullable();
            $table->decimal('dsr_after', 5, 4)->nullable();
            $table->boolean('eligibility_passed')->default(false);
            $table->json('eligibility_checks')->nullable();
            $table->enum('status', ['draft', 'pending', 'approved', 'disbursed', 'rejected'])->default('draft');
            $table->text('rejected_reason')->nullable();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('reference_loan_id')->references('id')->on('loans')->cascadeOnDelete();
            $table->foreign('new_loan_id')->references('id')->on('loans')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('staff')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_topup_applications');

        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            $table->dropForeign(['parent_loan_id']);
            $table->dropColumn(['parent_loan_id', 'topup_type']);
        });
    }
};
