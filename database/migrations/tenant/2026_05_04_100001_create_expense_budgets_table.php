<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_budgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('expense_category_id')->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('fiscal_year', 4)->index();
            $table->string('period_code', 7)->nullable()->index()->comment('e.g. 2026-05');
            $table->decimal('allocated_amount', 15, 2);
            $table->decimal('spent_amount', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['expense_category_id', 'branch_id', 'period_code'], 'idx_cat_branch_period');
            $table->foreign('expense_category_id')->references('id')->on('expense_categories');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_budgets');
    }
};
