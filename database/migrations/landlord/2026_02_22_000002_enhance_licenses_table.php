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
        $hasGrace = Schema::connection('master')->hasColumn('licenses', 'grace_ends_at');
        $hasMaxMembers = Schema::connection('master')->hasColumn('licenses', 'max_members');
        $hasMaxUsers = Schema::connection('master')->hasColumn('licenses', 'max_users');
        $hasFeatures = Schema::connection('master')->hasColumn('licenses', 'features');

        Schema::connection('master')->table('licenses', function (Blueprint $table) use ($hasGrace, $hasMaxMembers, $hasMaxUsers, $hasFeatures) {
            if (! $hasGrace) {
                $table->date('grace_ends_at')->nullable()->after('expires_at');
            }
            if (! $hasMaxMembers) {
                $table->integer('max_members')->nullable()->after('grace_ends_at');
            }
            if (! $hasMaxUsers) {
                $table->integer('max_users')->nullable()->after('max_members');
            }
            if (! $hasFeatures) {
                $table->json('features')->nullable()->after('max_users');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = ['grace_ends_at', 'max_members', 'max_users', 'features'];
        $toDrop = array_filter($columns, fn ($col) => Schema::connection('master')->hasColumn('licenses', $col));

        Schema::connection('master')->table('licenses', function (Blueprint $table) use ($toDrop) {
            if (! empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });
    }
};
