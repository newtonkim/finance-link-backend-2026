<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->date('schedule_date')->nullable()->after('disbursed_at');
        });

        Schema::table('loan_applications', function (Blueprint $table) {
            $table->date('schedule_date')->nullable()->after('disbursed_at');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn('schedule_date');
        });

        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropColumn('schedule_date');
        });
    }
};
