<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('transactions')) {
            return;
        }

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            if (! Schema::hasColumn('transactions', 'code')) {
                $table->string('code', 100)->nullable()->unique()->index();
            }

            $table->string('reference', 100)->unique()->index();
            $table->unsignedBigInteger('member_id')->nullable()->index();
            $table->string('type')->index(); // deposit, withdrawal, loan_repayment, share_purchase, fee
            $table->decimal('amount', 15, 2)->comment('amount saved after charges which is money at hand/wallet')->index();
            $table->decimal('charge_amount', 15, 2)->comment('charge amount on the amount')->index();
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->string('account_type', 50)->nullable()->index(); // polymorphic: savings_accounts, loans
            $table->text('narration')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrent()->index();
            $table->softDeletes();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
