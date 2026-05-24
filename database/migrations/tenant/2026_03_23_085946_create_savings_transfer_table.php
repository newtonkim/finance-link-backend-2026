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
        if (Schema::connection('tenant')->hasTable('savings_account_transfers')) {
            return;
        }
        Schema::connection('tenant')->create('savings_account_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->index();
            $table->decimal('amount', 14, 2)->index();
            $table->unsignedBigInteger('from_account_id')->index();
            $table->unsignedBigInteger('to_account_id')->index();
            $table->unsignedBigInteger('created_by')->useCurrent()->nullable()->index();
            $table->unsignedBigInteger('updated_by')->useCurrent()->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->useCurrent()->nullable()->index();
            $table->enum('system_type', ['system', 'user_created'])->nullable()->default('user_created')->index();
            $table->text('description')->nullable();
            $table->enum('status', ['rejected', 'pending', 'approved', 'cancelled', 'completed', 'failed'])->nullable()->default('pending')->index();
            $table->timestamp('transaction_date')->useCurrent()->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->nullable()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->nullable()->index();
            $table->timestamp('deleted_at')->nullable()->nullable()->index();
            $table->foreign('from_account_id')->references('id')->on('savings_accounts');
            $table->foreign('to_account_id')->references('id')->on('savings_accounts');
            $table->foreign('deleted_by')->references('id')->on('staff');
            $table->foreign('created_by')->references('id')->on('staff');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('savings_account_transfers');
    }
};
