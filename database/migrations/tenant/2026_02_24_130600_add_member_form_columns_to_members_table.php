<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('member_type')->default('new')->after('member_number')->index();
            $table->string('salutation')->nullable()->after('name')->index();
            $table->string('gender')->nullable()->after('salutation')->index();
            $table->string('other_contact', 20)->nullable()->after('phone')->index();
            $table->string('mobile_money_number', 20)->nullable()->after('other_contact')->index();
            $table->string('marital_status')->nullable()->after('email')->index();
            $table->string('nationality')->default('Uganda')->after('marital_status')->index();
            $table->string('next_of_kin')->nullable()->after('address')->index();
            $table->string('next_of_kin_contact', 20)->nullable()->after('next_of_kin')->index();
            $table->decimal('initial_deposit', 15, 2)->nullable()->after('next_of_kin_contact')->index();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn([
                'member_type',
                'salutation',
                'gender',
                'other_contact',
                'mobile_money_number',
                'marital_status',
                'nationality',
                'next_of_kin',
                'next_of_kin_contact',
                'initial_deposit',
            ]);
        });
    }
};
