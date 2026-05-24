<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('receipt_number', 100)->nullable()->after('reference')->index();
            $table->index('receipt_number')->index();
        });

        // Backfill receipt_number for existing rows.
        DB::connection('tenant')->table('transactions')
            ->whereNull('receipt_number')
            ->update([
                'receipt_number' => DB::raw("CASE WHEN type = 'charge' AND grouped_with IS NOT NULL THEN grouped_with ELSE reference END"),
            ]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['receipt_number']);
            $table->dropColumn('receipt_number');
        });
    }
};
