<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('loan_product_charge')) {
            return;
        }

        Schema::connection('tenant')->create('loan_product_charge', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_product_id');
            $table->unsignedBigInteger('loan_charge_id');
            $table->timestamps();

            $table->unique(['loan_product_id', 'loan_charge_id'], 'loan_product_charge_unique');

            $table->foreign('loan_product_id')->references('id')->on('loan_products')->cascadeOnDelete();
            $table->foreign('loan_charge_id')->references('id')->on('loan_charges')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_product_charge');
    }
};
