<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Links a charge (or any sub-transaction) back to its parent deposit/withdrawal reference.
            // When a deposit is reversed, all transactions sharing the same grouped_with value
            // are also reversed atomically.
            $table->string('grouped_with', 100)->nullable()->after('reversal_of');
            $table->index('grouped_with');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['grouped_with']);
            $table->dropColumn('grouped_with');
        });
    }
};
