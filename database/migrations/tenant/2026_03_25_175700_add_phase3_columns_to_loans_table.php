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
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loans', 'approved_by_id')) {
                $table->unsignedBigInteger('approved_by_id')->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'withdrawn_by')) {
                $table->unsignedBigInteger('withdrawn_by')->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'written_off_by_id')) {
                $table->unsignedBigInteger('written_off_by_id')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loans', 'written_off_by_id')) {
                $table->dropColumn('written_off_by_id');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'withdrawn_by')) {
                $table->dropColumn('withdrawn_by');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'approved_by_id')) {
                $table->dropColumn('approved_by_id');
            }
        });
    }
};
