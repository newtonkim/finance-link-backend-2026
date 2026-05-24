<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('savings_group_members')) {
            return;
        }

        Schema::connection('tenant')->create('savings_group_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('savings_group_id')->index();
            $table->foreign('savings_group_id', 'sgm_group_fk')->references('id')->on('savings_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('member_id')->index();
            $table->foreign('member_id', 'sgm_member_fk')->references('id')->on('members')->cascadeOnDelete();
            $table->string('code')->nullable()->index();
            $table->enum('role', ['member', 'secretary', 'chairman', 'treasurer', 'admin'])->default('Member')->index();
            $table->string('account_number')->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['savings_group_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('savings_group_members');
    }
};
