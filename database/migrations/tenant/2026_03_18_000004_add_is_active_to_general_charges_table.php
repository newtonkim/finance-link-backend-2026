<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('general_charges', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('is_reversible');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('general_charges', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
