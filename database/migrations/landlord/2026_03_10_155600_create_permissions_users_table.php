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
        if (! Schema::connection('master')->hasTable('permissions_users')) {
            Schema::connection('master')->create('permissions_users', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->json('permission_ids')->nullable()->comment('json array of permission ids looks like [1,2,3]');
                $table->foreign('user_id')
                    ->references('id')
                    ->on('platform_users')
                    ->onDelete('cascade');

                $table->index(['id', 'user_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('master')->dropIfExists('permissions_users');
    }
};
