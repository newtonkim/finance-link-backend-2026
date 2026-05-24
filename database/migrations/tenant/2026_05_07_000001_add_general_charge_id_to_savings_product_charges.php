<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('savings_product_charges', function (Blueprint $table) {
            $table->unsignedBigInteger('general_charge_id')->nullable()->after('savings_product_id');
            $table->foreign('general_charge_id')
                ->references('id')
                ->on('general_charges')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('savings_product_charges', function (Blueprint $table) {
            $table->dropForeign(['general_charge_id']);
            $table->dropColumn('general_charge_id');
        });
    }
};
