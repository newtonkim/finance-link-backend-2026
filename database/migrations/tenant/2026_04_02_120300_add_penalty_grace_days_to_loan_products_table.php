<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_products')) {
            return;
        }

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_products', 'penalty_grace_days')) {
                $table->unsignedInteger('penalty_grace_days')->default(0)->after('grace_period');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_products')) {
            return;
        }

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_products', 'penalty_grace_days')) {
                $table->dropColumn('penalty_grace_days');
            }
        });
    }
};
