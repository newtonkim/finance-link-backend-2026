<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('expenses')) {
            return;
        }

        Schema::connection('tenant')->table('expenses', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('expenses', 'type')) {
                $table->string('type')->default('cash')->index()->after('status')->comment('cash or accrual');
            }
            if (! Schema::connection('tenant')->hasColumn('expenses', 'current_approval_level')) {
                $table->integer('current_approval_level')->default(0)->after('type');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('expenses')) {
            return;
        }

        Schema::connection('tenant')->table('expenses', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('expenses', 'type')) {
                $table->dropColumn('type');
            }
            if (Schema::connection('tenant')->hasColumn('expenses', 'current_approval_level')) {
                $table->dropColumn('current_approval_level');
            }
        });
    }
};
