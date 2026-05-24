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
        if (Schema::connection('tenant')->hasTable('loan_write_offs')) {
            return;
        }

        Schema::connection('tenant')->create('loan_write_offs', function (Blueprint $table) {
            $table->id();
            $table->string('writeoff_id')->nullable()->index();
            $table->unsignedBigInteger('loan_id')->index();
            $table->decimal('writeoff_amount', 15, 2)->default(0);
            $table->date('writeoff_date')->nullable()->index();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->string('recovery_status')->nullable()->index();
            $table->decimal('amount_recovered', 15, 2)->default(0);
            $table->date('recovery_date')->nullable()->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('loan_id')->references('id')->on('loans')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_write_offs');
    }
};
