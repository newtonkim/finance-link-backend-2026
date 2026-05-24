<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('gl_credit_account_id')->nullable()->after('charge_name');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->dropColumn('gl_credit_account_id');
        });
    }
};
