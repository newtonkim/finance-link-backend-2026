<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('transaction_reversals', function (Blueprint $table) {
            $table->unsignedBigInteger('assigned_approver_id')->nullable()->after('requested_by')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('transaction_reversals', function (Blueprint $table) {
            $table->dropColumn('assigned_approver_id');
        });
    }
};
