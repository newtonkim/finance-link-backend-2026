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
            if (! Schema::connection('tenant')->hasColumn('expenses', 'is_over_budget')) {
                $table->boolean('is_over_budget')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('expenses')) {
            return;
        }

        Schema::connection('tenant')->table('expenses', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('expenses', 'is_over_budget')) {
                $table->dropColumn('is_over_budget');
            }
        });
    }
};
