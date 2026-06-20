<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->table('platform_users', function (Blueprint $table) {
            if (! Schema::connection('master')->hasColumn('platform_users', 'avatar')) {
                $table->string('avatar')->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('master')->table('platform_users', function (Blueprint $table) {
            if (Schema::connection('master')->hasColumn('platform_users', 'avatar')) {
                $table->dropColumn('avatar');
            }
        });
    }
};
