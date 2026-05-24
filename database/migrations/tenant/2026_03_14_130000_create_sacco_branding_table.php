<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('sacco_branding')) {
            Schema::connection('tenant')->create('sacco_branding', function (Blueprint $table) {
                $table->id();
                $table->string('sacco_name')->nullable()->index();
                $table->string('tagline')->nullable()->index();
                $table->string('logo_path')->nullable()->index();
                // $table->timestamps();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('sacco_branding');
    }
};
