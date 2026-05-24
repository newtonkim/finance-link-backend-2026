<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('share_payment_account_id')->nullable()->after('share_price');
            $table->foreign('share_payment_account_id')
                ->references('id')->on('chart_of_accounts')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            $table->dropForeign(['share_payment_account_id']);
            $table->dropColumn('share_payment_account_id');
        });
    }
};
