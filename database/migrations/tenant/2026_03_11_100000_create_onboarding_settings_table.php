<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('onboarding_settings')) {
            return;
        }

        Schema::connection('tenant')->create('onboarding_settings', function (Blueprint $table) {
            $table->id();

            // Shares
            $table->boolean('shares_compulsory')->default(false)->index();
            $table->unsignedInteger('min_shares_on_onboarding')->default(1)->index();
            $table->decimal('share_price', 15, 2)->default(0.00)->index();
            $table->boolean('shares_compulsory_applies_to_existing')->default(false)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('onboarding_settings');
    }
};
