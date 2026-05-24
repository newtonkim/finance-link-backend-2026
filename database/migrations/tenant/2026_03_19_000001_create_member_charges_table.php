<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('member_charges')) {
            return;
        }

        Schema::connection('tenant')->create('member_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('general_charge_id')->nullable()->index(); // nullable so nullOnDelete works
            $table->unsignedBigInteger('savings_account_id')->nullable()->index(); // set when paid
            $table->string('charge_name')->index();                                // snapshot at creation
            $table->decimal('amount', 15, 2);                            // snapshot at creation
            $table->enum('status', ['pending', 'paid', 'waived'])->default('pending')->index();
            $table->date('due_date')->nullable()->index();
            $table->timestamp('applied_at')->index();
            $table->timestamp('paid_at')->nullable()->index();
            $table->unsignedBigInteger('transaction_id')->nullable()->index();    // Transaction that collected it
            $table->text('narration')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();

            $table->foreign('member_id')
                ->references('id')->on('members')
                ->cascadeOnDelete();

            $table->foreign('general_charge_id')
                ->references('id')->on('general_charges')
                ->nullOnDelete();

            $table->foreign('transaction_id')
                ->references('id')->on('transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('member_charges');
    }
};
