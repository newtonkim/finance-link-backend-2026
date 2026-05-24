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
        if (Schema::connection('tenant')->hasTable('loan_status_history_audit')) {
            return;
        }

        Schema::connection('tenant')->create('loan_status_history_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('loan_id')->index();
            $table->unsignedBigInteger('changed_by')->nullable()->index();
            $table->string('old_status')->nullable()->index();
            $table->string('new_status')->index();
            $table->text('change_reason')->nullable();
            $table->dateTime('changed_at')->index();
            $table->string('ip_address')->nullable();
            $table->string('device')->nullable();
            $table->timestamps();

            $table->foreign('loan_id')->references('id')->on('loans')->cascadeOnDelete();
            $table->foreign('changed_by')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('loan_status_history_audit');
    }
};
