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
        if (Schema::connection('tenant')->hasTable('loan_notifications')) {
            return;
        }

        Schema::connection('tenant')->create('loan_notifications', function (Blueprint $table) {
            $table->id();
            $table->enum('system_type', ['system', 'user_created'])->default('user_created')->index();
            $table->unsignedBigInteger('loan_id')->index();
            $table->unsignedBigInteger('member_id')->index();
            $table->enum('channel', ['SMS', 'EMAIL', 'WHATSAPP'])->index();
            $table->string('template_id')->nullable()->index();
            $table->text('message_body');
            $table->dateTime('sent_at')->nullable()->index();
            $table->string('delivery_status')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('loan_id')->references('id')->on('loans')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('sent_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_notifications');
    }
};
