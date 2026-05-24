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
        if (! Schema::hasTable('savings_product_charges')) {
            Schema::create('savings_product_charges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('savings_product_id')->constrained()->cascadeOnDelete()->index();
                $table->enum('type', ['deposit', 'withdraw', 'transfer'])->index();
                $table->decimal('minimum_amount', 15, 2)->default(0)->index();
                $table->decimal('maximum_amount', 15, 2)->nullable()->index();
                $table->enum('charge_type', ['percentage', 'amount'])->index();
                $table->decimal('amount', 15, 2);
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('savings_product_charges');
    }
};
