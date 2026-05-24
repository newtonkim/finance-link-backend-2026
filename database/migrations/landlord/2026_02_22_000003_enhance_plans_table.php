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
        $hasBillingCycle = Schema::connection('master')->hasColumn('plans', 'billing_cycle');
        $hasMaxMembers = Schema::connection('master')->hasColumn('plans', 'max_members');
        $hasMaxUsers = Schema::connection('master')->hasColumn('plans', 'max_users');
        $deletedAt = Schema::connection('master')->hasColumn('plans', 'deleted_at');

        Schema::connection('master')->table('plans', function (Blueprint $table) use ($hasBillingCycle, $deletedAt, $hasMaxMembers, $hasMaxUsers) {
            if (! $hasBillingCycle) {
                $table->string('billing_cycle')->default('monthly')->after('price');
            }
            if (! $hasMaxMembers) {
                $table->integer('max_members')->nullable()->after('billing_cycle');
            }
            if (! $hasMaxUsers) {
                $table->integer('max_users')->nullable()->after('max_members');
            }
            if (! $deletedAt) {
                $table->integer('deleted_at')->nullable()->after('max_members');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = ['billing_cycle', 'max_members', 'max_users'];
        $toDrop = array_filter($columns, fn ($col) => Schema::connection('master')->hasColumn('plans', $col));

        Schema::connection('master')->table('plans', function (Blueprint $table) use ($toDrop) {
            if (! empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });
    }
};
