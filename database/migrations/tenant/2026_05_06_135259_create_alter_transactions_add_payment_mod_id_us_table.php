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
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'payment_mod_account_id')) {
                $table->unsignedBigInteger('payment_mod_account_id')->nullable()->index()->after('payment_mode')->comment('The payment method use_for the transaction (debit account) the id comes from  chart of account');
            }
            if (! Schema::hasColumn('transactions', 'deposited_amount_before_charge')) {
                $table->decimal('deposited_amount_before_charge')->nullable()->index()->after('meta_details_before_transaction')->comment('this will be the amount that was actually deposited to the account before any charges were applied');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
