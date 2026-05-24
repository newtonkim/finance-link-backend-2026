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
        if (Schema::connection('tenant')->hasTable('loan_products')) {
            return;
        }

        Schema::connection('tenant')->create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->decimal('min_amount', 15, 2)->default(0);
            $table->decimal('max_amount', 15, 2)->default(0);
            $table->decimal('interest_rate', 15, 2)->default(0);
            $table->string('interest_method')->nullable();
            $table->string('interest_period')->nullable();
            $table->integer('loan_duration')->nullable();
            $table->string('duration_type')->nullable();
            $table->string('repayment_cycle')->nullable();
            $table->integer('max_guarantors')->default(0);
            $table->integer('min_guarantors')->default(0);
            $table->integer('grace_period')->default(0);
            $table->decimal('penalty_rate', 15, 2)->default(0);
            $table->string('penalty_type')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_products');
    }
};
