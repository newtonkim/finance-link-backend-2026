<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend savings_product_charges.type ENUM to include 'registration'.
 *
 * Used by the per-product registration-charge feature. A charge with
 * application='on_registration' AND non-empty saving_product_ids writes one
 * pivot row per product with type='registration'. ChargeCalculatorService::
 * resolveForRegistration queries on that type to surface charges at member
 * registration time.
 *
 * down() restores the original three-value ENUM. Any 'registration' rows
 * present at rollback time would fail the column conversion, so callers must
 * delete those rows first.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('tenant')->statement(
            "ALTER TABLE savings_product_charges MODIFY COLUMN type ENUM('deposit','withdraw','transfer','registration') NOT NULL"
        );
    }

    public function down(): void
    {
        DB::connection('tenant')->statement(
            "ALTER TABLE savings_product_charges MODIFY COLUMN type ENUM('deposit','withdraw','transfer') NOT NULL"
        );
    }
};
