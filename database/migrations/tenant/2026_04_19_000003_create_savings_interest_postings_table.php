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
        if (Schema::connection('tenant')->hasTable('savings_interest_postings')) {
            return;
        }

        Schema::connection('tenant')->create('savings_interest_postings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('savings_account_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('principal', 15, 2);
            $table->decimal('rate', 5, 4);
            $table->decimal('interest_amount', 15, 2);
            $table->enum('payout_type', ['at_maturity', 'periodic_payout', 'compound']);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('posted_by')->nullable()->comment('null = system auto-post');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['savings_account_id', 'period_start', 'period_end'], 'uq_posting_period');
            $table->foreign('savings_account_id')->references('id')->on('savings_accounts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('savings_interest_postings');
    }
};
