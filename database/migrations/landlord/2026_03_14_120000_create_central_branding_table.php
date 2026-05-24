<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('central_branding', function (Blueprint $table) {
            $table->id();
            $table->string('platform_name')->nullable()->index();
            $table->string('tagline')->nullable()->index();
            $table->string('logo_path')->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('central_branding');
    }
};
