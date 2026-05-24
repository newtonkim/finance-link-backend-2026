<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('staff', function (Blueprint $table) {
            if (! Schema::hasColumn('staff', 'branch_can_be_accessed')) {
                $table->json('branch_can_be_accessed')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('staff', function (Blueprint $table) {
            $table->dropColumn(['branch_can_be_accessed']);
        });
    }
};
