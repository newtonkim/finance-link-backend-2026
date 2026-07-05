<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('group_withdrawal_requests')) {
            Schema::connection('tenant')->create('group_withdrawal_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('savings_group_id')->index();
                $table->unsignedBigInteger('group_savings_account_id')->index();
                $table->unsignedBigInteger('member_id')->index(); // requester
                $table->decimal('amount', 15, 2);
                $table->decimal('charge_amount', 15, 2)->default(0);
                $table->text('narration')->nullable();
                $table->unsignedBigInteger('payment_mode_id')->nullable();
                // pending | approved | rejected | cancelled | executed
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedTinyInteger('required_approvals')->default(1);
                $table->unsignedBigInteger('requested_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('savings_group_id')->references('id')->on('savings_groups')->cascadeOnDelete();
                $table->foreign('group_savings_account_id')->references('id')->on('group_savings_accounts')->cascadeOnDelete();
                $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            });
        }

        if (! Schema::connection('tenant')->hasTable('group_withdrawal_approvals')) {
            Schema::connection('tenant')->create('group_withdrawal_approvals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('group_withdrawal_request_id')->index();
                $table->unsignedBigInteger('approver_member_id')->index();
                $table->string('decision', 20); // approved | rejected
                $table->text('comment')->nullable();
                $table->timestamp('acted_at')->nullable();
                $table->timestamps();

                $table->foreign('group_withdrawal_request_id', 'gwa_request_fk')
                    ->references('id')->on('group_withdrawal_requests')->cascadeOnDelete();
                $table->foreign('approver_member_id', 'gwa_approver_fk')
                    ->references('id')->on('members')->cascadeOnDelete();
                // One vote per approver per request.
                $table->unique(['group_withdrawal_request_id', 'approver_member_id'], 'gwa_request_approver_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('group_withdrawal_approvals');
        Schema::connection('tenant')->dropIfExists('group_withdrawal_requests');
    }
};
