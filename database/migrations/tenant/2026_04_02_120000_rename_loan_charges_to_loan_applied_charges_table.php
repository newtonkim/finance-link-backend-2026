<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::connection('tenant')->hasTable('loan_charges')
            && ! Schema::connection('tenant')->hasTable('loan_applied_charges')
        ) {
            Schema::connection('tenant')->rename('loan_charges', 'loan_applied_charges');
        }
    }

    public function down(): void
    {
        if (
            Schema::connection('tenant')->hasTable('loan_applied_charges')
            && ! Schema::connection('tenant')->hasTable('loan_charges')
        ) {
            Schema::connection('tenant')->rename('loan_applied_charges', 'loan_charges');
        }
    }
};
