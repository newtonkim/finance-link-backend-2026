<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('loan_approval_settings')) {
            return;
        }

        Schema::connection('tenant')->create('loan_approval_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_product_id')->unique();
            $table->unsignedInteger('quorum_size')->default(3);
            $table->unsignedInteger('approval_threshold')->default(2);
            $table->json('amount_tiers')->nullable();
            $table->unsignedInteger('abstention_timeout_hours')->default(48);
            $table->timestamps();

            $table->foreign('loan_product_id')
                ->references('id')
                ->on('loan_products')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_approval_settings');
    }
};
