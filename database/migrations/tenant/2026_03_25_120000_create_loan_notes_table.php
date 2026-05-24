<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('loan_notes')) {
            return;
        }

        Schema::connection('tenant')->create('loan_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('note');
            $table->string('type')->default('general')
                ->comment('general, approval, rejection, disbursement, rescheduling, write_off, top_up');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('loan_id')->references('id')->on('loans')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_notes');
    }
};
