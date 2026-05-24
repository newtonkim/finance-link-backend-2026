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
            $table->string('payment_mode')->nullable()->after('amount')->index();
            $table->string('deposited_by')->nullable()->after('payment_mode')->index();
            $table->date('transaction_date')->nullable()->after('deposited_by')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['payment_mode', 'deposited_by', 'transaction_date']);
        });
    }
};
