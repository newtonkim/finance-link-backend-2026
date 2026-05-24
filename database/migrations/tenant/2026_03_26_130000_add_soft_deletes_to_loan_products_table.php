<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_products', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loan_products', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
