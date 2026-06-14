<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('master')->hasTable('central_currency_settings')) {
            return;
        }

        Schema::connection('master')->create('central_currency_settings', function (Blueprint $table) {
            $table->id();
            $table->string('default_currency', 10)->default('UGX')->index();
            $table->json('enabled_currencies');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('central_currency_settings');
    }
};
