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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('expense_category_id')->index();
            $table->unsignedBigInteger('chart_of_account_id')->nullable()->index()->comment('The Bank/Cash account paid from');
            $table->decimal('amount', 15, 2);
            $table->string('vendor_name')->nullable();
            $table->date('transaction_date');
            $table->string('reference_no')->nullable();
            $table->text('description')->nullable();
            $table->string('payment_method')->nullable(); // cash, bank, mobile_money, cheque
            $table->string('status')->default('Pending')->index(); // Pending, Approved, Paid, Rejected, Void
            
            // Recurring fields
            $table->boolean('is_recurring')->default(false);
            $table->string('recurring_frequency')->nullable(); // weekly, monthly, quarterly, annually
            $table->date('next_due_date')->nullable();
            
            // Tracking
            $table->unsignedBigInteger('created_by')->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->unsignedBigInteger('paid_by')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('expense_category_id')->references('id')->on('expense_categories');
            $table->foreign('chart_of_account_id')->references('id')->on('chart_of_accounts');
            $table->foreign('created_by')->references('id')->on('staff');
            $table->foreign('approved_by')->references('id')->on('staff');
            $table->foreign('paid_by')->references('id')->on('staff');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
