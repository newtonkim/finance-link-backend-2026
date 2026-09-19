<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The column was added out-of-band on some environments before this
        // migration existed, so adding it is guarded rather than assumed.
        if (Schema::connection('master')->hasColumn('platform_users', 'avatar')) {
            return;
        }

        Schema::connection('master')->table('platform_users', function (Blueprint $table) {
            // Relative path on the 'public' disk, e.g. avatars/abc123.jpg.
            $table->string('avatar')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        if (! Schema::connection('master')->hasColumn('platform_users', 'avatar')) {
            return;
        }

        Schema::connection('master')->table('platform_users', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }
};
