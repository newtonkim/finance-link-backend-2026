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
        Schema::connection('tenant')->table('loan_guarantors', function (Blueprint $table) {
            $table->unsignedBigInteger('loan_id')->nullable()->change();
            $table->unsignedBigInteger('loan_application_id')->nullable()->after('loan_id')->index();

            $table->foreign('loan_application_id')
                ->references('id')
                ->on('loan_applications')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loan_guarantors', function (Blueprint $table) {
            $table->dropForeign(['loan_application_id']);
            $table->dropColumn('loan_application_id');
            $table->unsignedBigInteger('loan_id')->nullable(false)->change();
        });
    }
};
