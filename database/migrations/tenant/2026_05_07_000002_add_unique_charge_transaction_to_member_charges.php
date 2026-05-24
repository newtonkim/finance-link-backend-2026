<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string
    {
        return 'tenant';
    }

    public function up(): void
    {
        Schema::connection('tenant')->table('member_charges', function (Blueprint $table) {
            // Partial unique: only enforce uniqueness when transaction_id is NOT NULL
            // MySQL does not support partial indexes natively, so we use a nullable unique
            // — NULLs are not considered equal in unique constraints, so rows with
            // transaction_id = NULL can coexist without violating the constraint.
            $table->unique(['general_charge_id', 'transaction_id'], 'uq_member_charge_gc_txn');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('member_charges', function (Blueprint $table) {
            $table->dropUnique('uq_member_charge_gc_txn');
        });
    }
};
