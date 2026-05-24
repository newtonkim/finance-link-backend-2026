<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('journal_entries', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('journal_entries', 'currency_code')) {
                $table->string('currency_code', 10)->default('UGX')->after('journal_type');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('journal_entries', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('journal_entries', 'currency_code')) {
                $table->dropColumn('currency_code');
            }
        });
    }
};
