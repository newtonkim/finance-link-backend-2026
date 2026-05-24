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
        Schema::table('members', function (Blueprint $table) {
            // Make email nullable since many SACCO members don't have one
            $table->string('email')->nullable()->change()->index()->index();
            // Add country code columns for the phone number->index()s
            $table->string('phone_country', 5)->default('UG')->after('phone')->index();
            $table->string('other_contact_country', 5)->default('UG')->after('other_contact')->index();
            $table->string('mobile_money_country', 5)->default('UG')->after('mobile_money_number')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();

            $table->dropColumn([
                'phone_country',
                'other_contact_country',
                'mobile_money_country',
            ]);
        });
    }
};
