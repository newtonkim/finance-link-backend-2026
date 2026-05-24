<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_products')) {
            return;
        }

        DB::connection('tenant')
            ->table('loan_products')
            ->orderBy('id')
            ->get()
            ->each(function (object $product): void {
                $updates = [];

                if (Schema::connection('tenant')->hasColumn('loan_products', 'code') && empty($product->code)) {
                    $base = Str::upper(Str::slug((string) ($product->name ?: 'loan-product'), '-'));
                    $base = $base !== '' ? Str::limit($base, 20, '') : 'LOAN-PRODUCT';
                    $updates['code'] = "{$base}-{$product->id}";
                }

                if (Schema::connection('tenant')->hasColumn('loan_products', 'requires_approval') && $product->requires_approval === null) {
                    $updates['requires_approval'] = false;
                }

                if (Schema::connection('tenant')->hasColumn('loan_products', 'allow_top_up') && $product->allow_top_up === null) {
                    $updates['allow_top_up'] = true;
                }

                if (Schema::connection('tenant')->hasColumn('loan_products', 'allow_reschedule') && $product->allow_reschedule === null) {
                    $updates['allow_reschedule'] = true;
                }

                if (Schema::connection('tenant')->hasColumn('loan_products', 'processing_fee_type') && empty($product->processing_fee_type)) {
                    $updates['processing_fee_type'] = 'none';
                }

                if (Schema::connection('tenant')->hasColumn('loan_products', 'processing_fee_value') && $product->processing_fee_value === null) {
                    $updates['processing_fee_value'] = 0;
                }

                if (
                    Schema::connection('tenant')->hasColumn('loan_products', 'repayment_structure')
                    && empty($product->repayment_structure)
                    && $product->interest_method === 'reducing_balance'
                ) {
                    $updates['repayment_structure'] = 'equal_installment';
                }

                if ($updates !== []) {
                    DB::connection('tenant')
                        ->table('loan_products')
                        ->where('id', $product->id)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        // Intentionally left blank. Backfill is not reversed.
    }
};
