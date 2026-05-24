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
        if (Schema::connection('tenant')->hasTable('system_settings')) {
            return;
        }
        Schema::connection('tenant')->create('system_settings', function (Blueprint $table) {
            $table->id()->index();
            $table->string('settings_name', 70)->unique()->index();
            $table->string('settings_title', 60)->nullable()->index();
            $table->string('settings_module', 40)->nullable()->index();
            $table->enum('settings_status', ['active', 'de-activated'])->default('active')->index();
            $table->json('settings_action');
            $table->text('settings_setting_description')->nullable();
            $table->text('settings_action_description')->nullable();
            $table->enum('system_type', ['system', 'user_created'])->nullable()->default('user_created')->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->bigInteger('created_by')->index();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->index();
            $table->bigInteger('updated_by')->index();
            $table->timestamp('deteleted_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('system_settings');
    }
};
