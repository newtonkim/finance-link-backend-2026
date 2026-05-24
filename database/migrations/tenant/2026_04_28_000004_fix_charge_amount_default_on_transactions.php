<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->decimal('charge_amount', 15, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->decimal('charge_amount', 15, 2)->nullable()->change();
        });
    }
};
