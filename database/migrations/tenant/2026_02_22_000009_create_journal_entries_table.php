<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('journal_entries')) {
            return;
        }

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_no', 50)->unique();
            $table->date('date');
            $table->string('reference', 100)->nullable();
            $table->text('narration')->nullable();
            $table->string('status')->default('draft'); // draft, posted, reversed
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('posted_by')->references('id')->on('staff')->nullOnDelete();
            $table->foreign('reversed_by')->references('id')->on('staff')->nullOnDelete();
            $table->index('date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
