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
        Schema::connection('tenant')->table('savings_accounts', function (Blueprint $table) {
            $table->unsignedInteger('tenor_months')->nullable()->after('status');
            $table->date('maturity_date')->nullable()->after('tenor_months')->index();
            $table->date('next_interest_date')->nullable()->after('maturity_date')->index();
            $table->enum('maturity_action', ['auto_rollover', 'manual', 'convert_to_savings'])
                ->nullable()->after('next_interest_date');
            $table->unsignedBigInteger('payout_savings_account_id')->nullable()->after('maturity_action');
            $table->timestamp('last_interest_posted_at')->nullable()->after('payout_savings_account_id');

            $table->foreign('payout_savings_account_id')->references('id')->on('savings_accounts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('savings_accounts', function (Blueprint $table) {
            $table->dropForeign(['payout_savings_account_id']);
            $table->dropColumn([
                'tenor_months', 'maturity_date', 'next_interest_date',
                'maturity_action', 'payout_savings_account_id', 'last_interest_posted_at',
            ]);
        });
    }
};
