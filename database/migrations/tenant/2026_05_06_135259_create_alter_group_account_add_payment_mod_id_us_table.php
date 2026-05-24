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
        Schema::table('group_savings_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('group_savings_accounts', 'payment_mod_account_id')) {
                return;
            }
            $table->unsignedBigInteger('payment_mod_account_id')->nullable()->index()->after('savings_group_id')->comment('The payment method use_for the transaction (debit account) the id comes from  chart of account');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
