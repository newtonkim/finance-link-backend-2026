<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('loan_application_approvals')) {
            return;
        }

        Schema::connection('tenant')->create('loan_application_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_application_id')->index();
            $table->unsignedBigInteger('approver_id')->index();
            $table->integer('level')->default(1)->index();
            $table->enum('decision', ['approved', 'rejected'])->default('approved')->index();
            $table->text('comments')->nullable();
            $table->timestamp('decided_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('loan_application_id')
                ->references('id')->on('loan_applications')
                ->cascadeOnDelete();

            $table->foreign('approver_id')
                ->references('id')->on('staff')
                ->restrictOnDelete();

            // One vote per approver per application
            $table->unique(['loan_application_id', 'approver_id'], 'uq_laa_application_approver');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_application_approvals');
    }
};
