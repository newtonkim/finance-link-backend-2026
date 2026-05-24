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
        if (Schema::connection('tenant')->hasTable('loan_guarantors')) {
            return;
        }

        Schema::connection('tenant')->create('loan_guarantors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_id')->index();
            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->decimal('guarantee_amount', 15, 2)->default(0);
            $table->string('guarantee_type')->nullable()->index();
            $table->decimal('max_guarantee_used', 15, 2)->default(0);
            $table->date('accepted_date')->nullable()->index();
            $table->date('released_date')->nullable()->index();
            $table->string('status')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('loan_id')->references('id')->on('loans')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_guarantors');
    }
};
