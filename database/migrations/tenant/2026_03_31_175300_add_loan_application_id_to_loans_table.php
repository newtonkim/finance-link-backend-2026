<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            $table->unsignedBigInteger('loan_application_id')->nullable()->after('loan_no');

            $table->foreign('loan_application_id')
                ->references('id')
                ->on('loan_applications')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            $table->dropForeign(['loan_application_id']);
            $table->dropColumn('loan_application_id');
        });
    }
};
