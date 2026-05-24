<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('sent_message_notifications')) {
            return;
        }

        Schema::create('sent_message_notifications', function (Blueprint $table) {

            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();

            $table->string('code', 100)->unique()->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->string('from_module')->index(); // e.g. On-loan-approval, loan-repayment,group-loan-approval,

            $table->string('sender_id', 100)->index();
            $table->string('receiver_id', 100)->index();

            $table->integer('str_length')->nullable()->comment('length of message');

            $table->enum('channel', ['in_app', 'email', 'sms', 'push', 'whatsapp', 'mms'])
                ->default('in_app')
                ->index();

            $table->enum('status', ['sent', 'failed', 'no-funds', 'in_progress', 'in_queue', 'cancelled', 'delivered', 'pending'])->default('pending')->index();

            $table->decimal('cost', 10, 2)->nullable()->comment('cost of message');
            $table->string('reason_for_failure', 200)->index();

            $table->json('other_data')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_time_at')->nullable();

            $table->unsignedBigInteger('notification_setting_id')->nullable()->index();

            $table->enum('sender_type', ['staff', 'member', 'system'])->nullable()->index();

            $table->enum('system_type', ['system', 'user_created'])
                ->default('system')
                ->index();

            $table->bigInteger('created_by')->nullable()->index();
            $table->bigInteger('updated_by')->nullable()->index();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('deleted_at')->nullable();

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->nullOnDelete();

            $table->foreign('notification_setting_id')
                ->references('id')
                ->on('message_notification_settings')
                ->nullOnDelete();

            $table->index(['status', 'channel', 'code']);
            $table->index(['from_module', 'status']);
            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_message_notifications');
    }
};
