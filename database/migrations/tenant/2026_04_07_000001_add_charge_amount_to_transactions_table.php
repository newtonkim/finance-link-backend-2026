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
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'charge_amount')) {
                $table->decimal('charge_amount', 15, 2)->default(0)->after('amount')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->dropColumn('charge_amount');
        });
    }
};
