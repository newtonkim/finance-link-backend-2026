<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_approval_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('expense_id')->index();
            $table->unsignedBigInteger('approver_id')->index();
            $table->integer('level');
            $table->string('action'); // approved, rejected, queried
            $table->text('comments')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('expense_id')->references('id')->on('expenses');
            $table->foreign('approver_id')->references('id')->on('staff');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_approval_history');
    }
};
