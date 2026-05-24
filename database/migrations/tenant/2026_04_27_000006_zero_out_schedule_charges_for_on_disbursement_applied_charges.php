<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When disbursement_fee / processing_fee charges were incorrectly stored as
     * application_timing = 'on_repayment', distributeChargesToSchedule() spread
     * their full amount across all installments as charges_due.
     *
     * Migration 000005 fixed loan_applied_charges.application_timing but left the
     * inflated charges_due on loan_repayment_schedule untouched.
     *
     * This migration corrects pending schedule rows for every loan by:
     *   1. Computing the CORRECT on_repayment total from loan_applied_charges.
     *   2. Computing how much of that total has already been collected (charges_paid
     *      on paid/partial rows).
     *   3. Distributing the remaining owed amount across pending rows exactly as
     *      distributeEvenly() does — first row absorbs the rounding difference.
     *   4. Adjusting total_due on each updated row accordingly.
     *
     * Safe to re-run: only rows where charges_due differs from the correct value
     * are touched.
     */
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_repayment_schedule')) {
            return;
        }

        // Collect all loans that have applied charges
        $loans = DB::connection('tenant')
            ->table('loan_applied_charges')
            ->select('loan_id', DB::raw(
                "SUM(CASE WHEN application_timing = 'on_repayment' AND is_waived = 0 THEN charge_amount ELSE 0 END) AS on_repayment_total"
            ))
            ->groupBy('loan_id')
            ->get();

        foreach ($loans as $loan) {
            $correctTotal = round((float) $loan->on_repayment_total, 2);

            // Sum of charges already collected on paid/partial installments
            $alreadyPaid = (float) DB::connection('tenant')
                ->table('loan_repayment_schedule')
                ->where('loan_id', $loan->loan_id)
                ->whereIn('status', ['paid', 'partial'])
                ->sum('charges_paid');

            // Remaining amount still owed on pending rows
            $remaining = max(0.0, round($correctTotal - $alreadyPaid, 2));

            // Pending rows (no charges collected yet on these rows)
            $pendingRows = DB::connection('tenant')
                ->table('loan_repayment_schedule')
                ->where('loan_id', $loan->loan_id)
                ->whereIn('status', ['pending', 'arrears'])
                ->where('charges_paid', '=', 0)
                ->orderBy('installment_no')
                ->get(['id', 'charges_due', 'total_due']);

            if ($pendingRows->isEmpty()) {
                continue;
            }

            $count = $pendingRows->count();

            // Replicate distributeEvenly() rounding logic
            $perInstallment = floor($remaining * 100 / $count) / 100;
            $rounding = round($remaining - ($perInstallment * $count), 2);

            foreach ($pendingRows as $index => $row) {
                // First pending row absorbs the rounding remainder
                $correctAmount = $index === 0
                    ? round($perInstallment + $rounding, 2)
                    : $perInstallment;

                $diff = round((float) $row->charges_due - $correctAmount, 2);

                if (abs($diff) < 0.001) {
                    continue; // already correct, skip
                }

                DB::connection('tenant')
                    ->table('loan_repayment_schedule')
                    ->where('id', $row->id)
                    ->update([
                        'charges_due' => $correctAmount,
                        'total_due' => max(0, round((float) $row->total_due - $diff, 2)),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Non-reversible data patch.
    }
};
