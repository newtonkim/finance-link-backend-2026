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
        if (Schema::hasColumn('savings_accounts', 'savings_product_id')) {
            return;
        }

        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->foreignId('savings_product_id')->after('member_id')->nullable()->constrained('savings_products')->nullOnDelete();
            $table->boolean('is_new_account')->default(true)->after('account_type')->index();
            $table->decimal('initial_deposit', 15, 2)->default(0)->after('balance')->index();
            $table->boolean('consider_min_balance')->default(true)->after('initial_deposit')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('savings_accounts', function (Blueprint $table) {
            $table->dropForeign(['savings_product_id']);
            $table->dropColumn(['savings_product_id', 'is_new_account', 'initial_deposit', 'consider_min_balance']);
        });
    }
};
