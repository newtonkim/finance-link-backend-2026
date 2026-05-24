<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('group_savings_accounts')) {
            return;
        }

        Schema::connection('tenant')->create('group_savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            $table->unsignedBigInteger('savings_group_id')->index();
            $table->foreign('savings_group_id', 'gsa_group_fk')
                ->references('id')->on('savings_groups')->cascadeOnDelete();

            $table->unsignedBigInteger('savings_product_id')->index();
            $table->foreign('savings_product_id', 'gsa_product_fk')
                ->references('id')->on('savings_products')->restrictOnDelete();

            // true = brand-new group savings account; false = link to an existing one
            $table->boolean('is_new_account')->default(true)->index();

            $table->decimal('initial_deposit', 15, 2)->default(0);

            $table->string('code')->nullable()->unique()->index();
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('status')->default('active')->index();

            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->foreign('created_by', 'gsa_created_by_fk')
                ->references('id')->on('staff')->nullOnDelete();
            $table->foreign('updated_by', 'gsa_updated_by_fk')
                ->references('id')->on('staff')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent()->nullable()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('group_savings_accounts');
    }
};
