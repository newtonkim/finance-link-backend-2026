<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('account_number')->nullable()->after('member_number')->index();
            $table->string('employee_number')->nullable()->after('account_number')->index();
            $table->unsignedInteger('shares_quantity')->nullable()->after('opening_balance')->index();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['account_number', 'employee_number', 'shares_quantity']);
        });
    }
};
