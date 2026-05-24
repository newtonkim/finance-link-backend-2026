<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('loan_application_collaterals', 'proof_path')) {
            return;
        }

        Schema::table('loan_application_collaterals', function (Blueprint $table) {
            $table->string('proof_path')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('loan_application_collaterals', 'proof_path')) {
            return;
        }

        Schema::table('loan_application_collaterals', function (Blueprint $table) {
            $table->dropColumn('proof_path');
        });
    }
};
