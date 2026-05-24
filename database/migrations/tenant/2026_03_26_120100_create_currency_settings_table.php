<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('currency_settings')) {
            return;
        }

        Schema::connection('tenant')->create('currency_settings', function (Blueprint $table) {
            $table->id();
            $table->string('default_currency', 10)->default('UGX')->index();
            $table->json('enabled_currencies');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('currency_settings');
    }
};
