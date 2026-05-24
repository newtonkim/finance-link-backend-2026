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
            if(Schema::connection('tenant')->hasColumn('transactions', 'group_member_account_balance_before_transaction')) {
                return;
            }
            $table->unsignedBigInteger('group_member_account_balance_before_transaction')->nullable()->after('amount_before_transactions')->comment('group member account balance before the transaction  this will be tell amount of monet account had before this transaction was made come from  group_savings_accounts table  balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
