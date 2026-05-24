<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('savings_products', 'interest_enabled')) {
            Schema::connection('tenant')->table('savings_products', function (Blueprint $table) {
                $table->boolean('interest_enabled')->default(false)->after('interest_payable_account_id');
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('savings_products', function (Blueprint $table) {
            $table->dropColumn('interest_enabled');
        });
    }
};
