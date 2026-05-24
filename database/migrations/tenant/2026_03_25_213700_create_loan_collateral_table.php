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
        if (Schema::connection('tenant')->hasTable('loan_collateral')) {
            return;
        }
        Schema::connection('tenant')->create('loan_collateral', function (Blueprint $table) {
            $table->id();
            $table->enum('system_type', ['system', 'user_created'])->default('user_created')->index();
            $table->unsignedBigInteger('loan_id')->index();
            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('collateral_type')->index();
            $table->text('description')->nullable();
            $table->decimal('estimated_value', 15, 2)->default(0);
            $table->decimal('forced_sale_value', 15, 2)->default(0);
            $table->string('serial_or_title_no')->nullable()->index();
            $table->string('verification_status')->nullable()->index();
            $table->unsignedBigInteger('verified_by')->nullable()->index();
            $table->date('verification_date')->nullable()->index();
            $table->date('release_date')->nullable()->index();
            $table->string('document_ref')->nullable()->index();
            $table->timestamps();

            $table->foreign('loan_id')->references('id')->on('loans')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('verified_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_collateral');
    }
};
