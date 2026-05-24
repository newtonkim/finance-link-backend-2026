<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: installment #1 of loan 21 stuck in 'arrears' after full payment.
 *
 * Root causes:
 * 1. The penalty calculator did not update total_due when adding penalty_due,
 *    so the payment modal showed total_due=381,988.55 (principal+interest+charges)
 *    instead of the correct 386,988.55 (including penalty 5,000).
 * 2. The penalty calculator unconditionally set status='arrears' after every run,
 *    overriding the 'partial' status set by applyToSchedules after the payment.
 * 3. The member paid 381,988.55 (the displayed total_due). Because allocation
 *    order applies penalty first, 5,000 went to penalty leaving principal 5,000
 *    short. The installment never reached 'paid'.
 *
 * Data correction applied:
 * - Set principal_paid = principal_due (absorb the 5,000 principal shortfall
 *   caused by the UX bug — the member paid in full per what was shown).
 * - Set status = 'paid', paid_date = payment date.
 * - Update total_due to include penalty_due (386,988.55) for historical accuracy.
 * - Reduce loan.outstanding_balance by 5,000 to stay consistent with the
 *   corrected principal_paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('tenant');

        // Fix installment #1 of loan 21
        $row = $db->table('loan_repayment_schedule')
            ->where('loan_id', 21)
            ->where('installment_no', 1)
            ->first();

        if (! $row || $row->status === 'paid') {
            return; // Already correct, nothing to do
        }

        $db->table('loan_repayment_schedule')
            ->where('id', $row->id)
            ->update([
                'status' => 'paid',
                'paid_date' => '2026-04-13',
                'principal_paid' => $row->principal_due,   // 171,738.55 (was 166,738.55)
                'total_due' => round(
                    (float) $row->principal_due
                    + (float) $row->interest_due
                    + (float) $row->charges_due
                    + (float) $row->penalty_due,
                    2
                ),
            ]);

        // Reduce loan outstanding_balance by the 5,000 principal shortfall that
        // was corrected above (166,738.55 → 171,738.55 = +5,000 in principal_paid).
        $principalDelta = round((float) $row->principal_due - (float) $row->principal_paid, 2);

        if ($principalDelta > 0) {
            $db->table('loans')
                ->where('id', 21)
                ->decrement('outstanding_balance', $principalDelta);
        }
    }

    public function down(): void
    {
        $db = DB::connection('tenant');

        $row = $db->table('loan_repayment_schedule')
            ->where('loan_id', 21)
            ->where('installment_no', 1)
            ->first();

        if (! $row) {
            return;
        }

        $db->table('loan_repayment_schedule')
            ->where('id', $row->id)
            ->update([
                'status' => 'arrears',
                'paid_date' => null,
                'principal_paid' => 166738.55,
                'total_due' => 381988.55,
            ]);

        $db->table('loans')
            ->where('id', 21)
            ->increment('outstanding_balance', 5000.00);
    }
};
