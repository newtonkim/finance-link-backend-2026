<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('member_transaction_requests')) {
            return;
        }

        Schema::connection('tenant')->create('member_transaction_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('savings_account_id')->index();
            $table->string('type', 20)->index();
            $table->decimal('amount', 15, 2);
            $table->string('payment_mode', 50);
            $table->text('narration')->nullable();
            $table->date('requested_date');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->unsignedBigInteger('linked_transaction_id')->nullable()->index();
            $table->string('receipt_number', 100)->nullable()->index();
            $table->string('transaction_reference', 100)->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('savings_account_id')->references('id')->on('savings_accounts')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('linked_transaction_id')->references('id')->on('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('member_transaction_requests');
    }
};
