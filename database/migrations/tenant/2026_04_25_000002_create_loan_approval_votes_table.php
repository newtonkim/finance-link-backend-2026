<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('loan_approval_votes')) {
            return;
        }

        Schema::connection('tenant')->create('loan_approval_votes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_application_id');
            $table->unsignedBigInteger('staff_id');
            $table->enum('decision', ['approve', 'decline']);
            $table->text('comment')->nullable();
            $table->boolean('abstained')->default(false);
            $table->unsignedBigInteger('abstained_by')->nullable();
            $table->timestamps();

            $table->unique(['loan_application_id', 'staff_id']);

            $table->foreign('loan_application_id')
                ->references('id')
                ->on('loan_applications')
                ->cascadeOnDelete();

            $table->foreign('staff_id')
                ->references('id')
                ->on('staff')
                ->cascadeOnDelete();

            $table->foreign('abstained_by')
                ->references('id')
                ->on('staff')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_approval_votes');
    }
};
