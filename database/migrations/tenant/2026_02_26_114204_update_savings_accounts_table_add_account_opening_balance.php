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
        Schema::table('savings_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('savings_accounts', 'account_opening_balance')) {
                return;
            }
            $table->decimal('account_opening_balance', 15, 2)->default(0)->after('is_new_account')
                ->comment('Opening balance for the savings account NOTE NEVER CHANGE THIS VALUE in any way, this is a read-only field')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->dropColumn(['account_opening_balance']);
        });
    }
};
