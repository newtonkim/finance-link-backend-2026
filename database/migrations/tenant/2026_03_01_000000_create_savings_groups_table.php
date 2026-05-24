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
        if (Schema::connection('tenant')->hasTable('savings_groups')) {
            return;
        }

        Schema::connection('tenant')->create('savings_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('group_type')->index()->nullable();
            $table->string('group_category')->index()->nullable();
            $table->decimal('total_balance', 15, 2)->nullable();
            $table->string('code')->index();
            $table->string('primary_contact_country_code', 5)->default('UG')->index();
            $table->string('primary_contact_phone');
            $table->string('other_contact_country_code', 5)->default('UG')->index();
            $table->string('other_contact_phone')->nullable()->index();
            $table->date('date_created')->index();
            $table->text('location');
            $table->text('description');
            $table->string('status')->default('active')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->foreign('created_by', 'sg_created_by_fk')->references('id')->on('staff')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->index();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('savings_groups');
    }
};
