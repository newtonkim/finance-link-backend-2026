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
        if (Schema::connection('tenant')->hasTable('loan_transactions')) {
            return;
        }

        Schema::connection('tenant')->create('loan_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('payment_id')->nullable()->index();
            $table->unsignedBigInteger('loan_id')->index();
            $table->unsignedBigInteger('member_id')->index();
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('principal_portion', 15, 2)->default(0);
            $table->decimal('interest_portion', 15, 2)->default(0);
            $table->decimal('penalty_portion', 15, 2)->default(0);
            $table->decimal('charges_portion', 15, 2)->default(0);
            $table->date('payment_date')->nullable()->index();
            $table->string('payment_method')->nullable()->index();
            $table->string('receipt_no')->nullable()->index();
            $table->unsignedBigInteger('collected_by')->nullable()->index();
            $table->boolean('reversal_flag')->default(false)->index();
            $table->unsignedBigInteger('reversed_by')->nullable()->index();
            $table->dateTime('reversed_date')->nullable();
            $table->string('transaction_ref')->nullable()->index();
            $table->timestamps();

            $table->foreign('loan_id')->references('id')->on('loans')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('collected_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('reversed_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_transactions');
    }
};
