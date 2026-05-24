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
        Schema::table('members', function (Blueprint $table) {
            $table->string('is_shareholder', 10)->nullable()->after('initial_deposit')->index();
            $table->decimal('opening_balance', 15, 2)->nullable()->after('is_shareholder')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['is_shareholder', 'opening_balance']);
        });
    }
};
