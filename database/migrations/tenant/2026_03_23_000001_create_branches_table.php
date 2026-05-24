<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('branches')) {
            return;
        }

        Schema::connection('tenant')->create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('code')->unique()->nullable()->index();
            $table->string('phone')->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('address')->nullable()->index();
            // $table->string('manager_name')->nullable()->index();
            $table->string('manager_id')->nullable()->comment('staff id from staff table -> id  to show the manager')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->unsignedBigInteger('deleted_by')->nullable()->index();
            $table->enum('system_type', ['system', 'user_created'])->nullable()->default('user_created')->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->index();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('branches');
    }
};
