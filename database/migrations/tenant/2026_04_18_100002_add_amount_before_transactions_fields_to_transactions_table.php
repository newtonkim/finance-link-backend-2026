<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'amount_before_transactions')) {
                $table->string('amount_before_transactions')->nullable()->after('type')->index()->comment('this will be tell amount of money account had before this transaction was made');
            }

        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->dropColumn(['amount_before_transactions']);
        });
    }
};
