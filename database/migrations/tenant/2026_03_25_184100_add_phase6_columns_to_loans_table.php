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
            if (! Schema::connection('tenant')->hasColumn('loans', 'applied_amount')) {
                $table->decimal('applied_amount', 15, 2)->default(0)->nullable()->after('principal');
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'approved_amount')) {
                $table->decimal('approved_amount', 15, 2)->default(0)->nullable()->after('applied_amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loans', 'approved_amount')) {
                $table->dropColumn('approved_amount');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'applied_amount')) {
                $table->dropColumn('applied_amount');
            }
        });
    }
};
