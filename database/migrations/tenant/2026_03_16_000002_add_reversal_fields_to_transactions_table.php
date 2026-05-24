<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->boolean('is_reversed')->default(false)->after('is_migrated');
            $table->unsignedBigInteger('reversal_of')->nullable()->after('is_reversed');
            $table->foreign('reversal_of')->references('id')->on('transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->dropForeign(['reversal_of']);
            $table->dropColumn(['is_reversed', 'reversal_of']);
        });
    }
};
