<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: duplicate "Processing Fee" in loan_applied_charges.
 *
 * Root cause: the Development Loan product (id=1) had BOTH a product-level
 * processing_fee_type='percentage', processing_fee_value=1.00 AND a linked
 * LoanCharge (id=1) with category='processing_fee', charge_type='flat', value=5500.
 * LoanDisbursementService::persistAppliedCharges() created a row for each,
 * producing two "Processing Fee" rows per disbursement.
 *
 * Fixes applied:
 * 1. Update the product to reflect the intended flat 5,500 processing fee
 *    (clearing the erroneous 1% configuration).
 * 2. Delete the incorrect percentage-based applied charge row on loan 21.
 * 3. Zero out loan 21's processing_fee field (the erroneous 20,000 product-level
 *    fee). The correct 5,500 flat fee is already tracked in loan_applied_charges
 *    (id=12) and included in total_charges_deducted.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fix 1 — Correct the product-level processing fee on Development Loan (id=1).
        // The intended fee is flat 5,500, not 1% of principal.
        DB::connection('tenant')
            ->table('loan_products')
            ->where('id', 1)
            ->where('processing_fee_type', 'percentage')
            ->where('processing_fee_value', 1.00)
            ->update([
                'processing_fee_type' => 'flat',
                'processing_fee_value' => 5500.00,
            ]);

        // Fix 2 — Delete the bad percentage-based Processing Fee row on loan 21.
        // Applied charge id=11: Processing Fee percentage 20,000 — created in error.
        // The correct flat 5,500 row (id=12) remains active.
        DB::connection('tenant')
            ->table('loan_applied_charges')
            ->where('id', 11)
            ->where('loan_id', 21)
            ->whereNull('charge_id')
            ->where('charge_type', 'percentage')
            ->delete();

        // Fix 3 — Zero out the erroneous product-level processing_fee on loan 21.
        // The 20,000 was calculated from the wrong 1% product setting. The correct
        // 5,500 is already captured in total_charges_deducted via the LoanCharge row.
        DB::connection('tenant')
            ->table('loans')
            ->where('id', 21)
            ->where('processing_fee', 20000.00)
            ->update(['processing_fee' => 0]);
    }

    public function down(): void
    {
        DB::connection('tenant')
            ->table('loan_products')
            ->where('id', 1)
            ->update([
                'processing_fee_type' => 'percentage',
                'processing_fee_value' => 1.00,
            ]);

        DB::connection('tenant')
            ->table('loans')
            ->where('id', 21)
            ->update(['processing_fee' => 20000.00]);

        // Note: the deleted applied charge row cannot be restored in down() as its
        // auto-increment id and exact timestamps are unknown post-deletion.
    }
};
