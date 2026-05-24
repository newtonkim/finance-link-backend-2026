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
            if (! Schema::hasColumn('members', 'dormant_date')) {
                $table->string('dormant_date')->nullable()->after('deleted_at')->index();
                //// require by abraham
                    Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['dormant_date']);
                        $table->unique('email');

        });
    }
};
