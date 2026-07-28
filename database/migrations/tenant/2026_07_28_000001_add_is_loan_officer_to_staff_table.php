<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasColumn('staff', 'is_loan_officer')) {
            return;
        }

        Schema::connection('tenant')->table('staff', function (Blueprint $table) {
            $table->boolean('is_loan_officer')
                ->default(false)
                ->after('status')
                ->index();
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasColumn('staff', 'is_loan_officer')) {
            return;
        }

        Schema::connection('tenant')->table('staff', function (Blueprint $table) {
            $table->dropColumn('is_loan_officer');
        });
    }
};
