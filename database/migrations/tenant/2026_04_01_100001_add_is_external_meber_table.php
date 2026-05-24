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
        Schema::connection('tenant')->table('members', function (Blueprint $table) {
            if (! Schema::hasColumn('members', 'is_external_member')) {
                $table->boolean('is_external_member')->default(false)->after('id')->index();
            }
            if (! Schema::hasColumn('members', 'created_from')) {
                $table->string('created_from', 70)->default('normal')->after('is_external_member')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('members', function (Blueprint $table) {
            $table->dropColumn('is_external_member');
            $table->dropColumn('created_from');
        });
    }
};
