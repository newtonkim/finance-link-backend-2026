<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('savings_product_charges', function (Blueprint $table) {
            $table->string('name')->nullable()->after('savings_product_id')->index();
            $table->boolean('is_reversible')->default(true)->after('amount')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('savings_product_charges', function (Blueprint $table) {
            $table->dropColumn(['name', 'is_reversible']);
        });
    }
};
