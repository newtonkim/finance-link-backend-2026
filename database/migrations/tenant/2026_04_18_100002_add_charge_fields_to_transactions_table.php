<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'charge_name')) {
                $table->string('charge_name')->nullable()->after('narration')->index();
            }
            if (! Schema::hasColumn('transactions', 'savings_account_transfers_id')) {
                $table->unsignedBigInteger('savings_account_transfers_id')->nullable()->after('code')->index();
            }
            if (! Schema::hasColumn('transactions', 'is_reversible')) {
                $table->boolean('is_reversible')->default(true)->after('charge_name')->index();
            }
            if (Schema::connection('tenant')->hasTable('savings_transfer')) {
                $table->foreign('savings_account_transfers_id')->references('id')->on('savings_transfer');
            }

        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
            $table->dropColumn(['charge_name', 'is_reversible']);
        });
    }
};
