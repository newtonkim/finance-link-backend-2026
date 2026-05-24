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
        if (! Schema::hasTable('savings_products')) {
            Schema::create('savings_products', function (Blueprint $table) {
                $table->id();
                $table->string('name')->index();
                $table->string('code')->index();
                $table->enum('type', ['fixed', 'standard'])->index();
                $table->decimal('minimum_balance', 15, 2)->default(0)->index();
                $table->integer('minimum_maturity_months')->default(0)->index();
                $table->integer('dormancy_period_months')->default(0)->index();
                $table->boolean('charge_on_deposit')->default(false)->index();
                $table->boolean('charge_on_withdraw')->default(false)->index();
                $table->boolean('charge_on_transfer')->default(false)->index();
                $table->enum('status', ['active', 'inactive'])->default('active')->index();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('savings_products');
    }
};
