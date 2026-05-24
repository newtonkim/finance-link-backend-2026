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
        if (Schema::connection('tenant')->hasTable('loan_settings')) {
            return;
        }

        Schema::connection('tenant')->create('loan_settings', function (Blueprint $table) {
            $table->id();
            $table->enum('system_type', ['system', 'user_created'])->default('user_created')->index();
            $table->unsignedBigInteger('branch_id')->index();
            $table->integer('min_approvers')->default(1);
            $table->integer('max_approvers')->default(3);
            $table->unsignedBigInteger('member_id')->nullable()->index();
            $table->string('guarantor_mode')->nullable();
            $table->unsignedBigInteger('loan_id')->nullable()->index();
            $table->string('holiday_skip_mode')->nullable();
            $table->unsignedBigInteger('guarantor_id')->nullable()->index();
            $table->unsignedBigInteger('public_holiday_id')->nullable()->index();
            $table->boolean('allow_top_up')->default(true);
            $table->boolean('allow_reschedule')->default(true);
            $table->unsignedBigInteger('loan_reschedule_id')->nullable()->index();
            $table->boolean('auto_penalty')->default(true);
            $table->integer('penalty_grace_days')->default(0);
            $table->string('notification_channels')->nullable();
            $table->integer('loan_cycle_limit')->default(1);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
            $table->foreign('loan_id')->references('id')->on('loans')->nullOnDelete();
            // FK to loan_application_guarantors omitted intentionally — that table is created later.
            $table->foreign('public_holiday_id')->references('id')->on('public_holidays')->nullOnDelete();
            $table->foreign('loan_reschedule_id')->references('id')->on('loan_rescheduling')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_settings');
    }
};
