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
        if (Schema::connection('master')->hasTable('licenses')) {
            return;
        }

        Schema::connection('master')->create('licenses', function (Blueprint $table) {
            $table->uuid('id')->primary()->index();
            $table->uuid('tenant_id')->index();
            $table->string('plan')->index(); // monthly, yearly
            $table->date('starts_at')->index();
            $table->date('expires_at')->index();
            $table->date('grace_ends_at')->nullable()->index();
            $table->integer('max_members')->nullable()->index();
            $table->integer('max_users')->nullable()->index();
            $table->json('features')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('master')->dropIfExists('licenses');
    }
};
