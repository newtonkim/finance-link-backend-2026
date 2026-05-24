<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Concerns\FormatsLoanActivity;
use App\Tenant\Modules\Loans\Contracts\LoanActivityServiceInterface;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanReschedule;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Modules\Loans\Models\LoanStatusHistory;
use App\Tenant\Modules\Loans\Models\LoanTransaction;
use App\Tenant\Support\TenantMoney;
use Illuminate\Support\Collection;

class LoanActivityService implements LoanActivityServiceInterface
{
    use FormatsLoanActivity;

    /**
     * Return a chronological list of all events on the given loan.
     */
    public function getActivity(Loan $loan): Collection
    {
        $disbursed = $this->disbursedEvent($loan);
        $statusEvents = $this->statusEvents($loan);
        $repayments = $this->repaymentEvents($loan);
        $penalties = $this->penaltyEvents($loan);
        $reschedules = $this->rescheduleEvents($loan);

        return collect([$disbursed])
            ->merge($statusEvents)
            ->merge($repayments)
            ->merge($penalties)
            ->merge($reschedules)
            ->sortBy('timestamp')
            ->values();
    }

    // ─── Private builders ─────────────────────────────────────────────────────

    private function disbursedEvent(Loan $loan): array
    {
        $loan->loadMissing('disbursedBy');

        $actor = null;
        if ($loan->disbursedBy) {
            $actor = $this->staffSummary($loan->disbursedBy);
        }

        $principal = TenantMoney::format((float) $loan->principal);
        $netDisbursed = TenantMoney::format((float) $loan->net_disbursed_amount);

        return [
            'type' => 'disbursed',
            'title' => 'Loan Disbursed',
            'description' => "{$principal} disbursed via {$loan->disbursement_method}. Net received: {$netDisbursed}.",
            'amount' => TenantMoney::format((float) $loan->net_disbursed_amount),
            'actor' => $actor,
            'notes' => null,
            'timestamp' => $loan->disbursed_at,
        ];
    }

    private function statusEvents(Loan $loan): Collection
    {
        $histories = LoanStatusHistory::with('changedBy')
            ->where('loan_id', $loan->id)
            ->orderBy('changed_at')
            ->get();

        return $histories->map(function (LoanStatusHistory $h) {
            $from = $this->labelStatus($h->from_status);
            $to = $this->labelStatus($h->to_status);
            $actor = $h->changedBy ? $this->staffSummary($h->changedBy) : null;

            return [
                'type' => 'status_change',
                'title' => "Status: {$from} → {$to}",
                'description' => "Loan status changed from '{$h->from_status}' to '{$h->to_status}'.",
                'amount' => null,
                'actor' => $actor,
                'notes' => $h->notes,
                'timestamp' => $h->changed_at,
            ];
        });
    }

    private function repaymentEvents(Loan $loan): Collection
    {
        $transactions = $loan->repayments()
            ->with('collectedBy')
            ->where('reversal_flag', false)
            ->orderBy('payment_date')
            ->get();

        return $transactions->map(function (LoanTransaction $t) {
            $actor = $t->collectedBy ? $this->staffSummary($t->collectedBy) : null;
            $amountFormatted = TenantMoney::format((float) $t->amount_paid);
            $receiptNo = $t->receipt_no ?? 'N/A';

            return [
                'type' => 'repayment',
                'title' => 'Repayment Received',
                'description' => "{$amountFormatted} received via {$t->payment_method}. Receipt: {$receiptNo}.",
                'amount' => TenantMoney::format((float) $t->amount_paid),
                'actor' => $actor,
                'notes' => null,
                'timestamp' => $t->payment_date,
            ];
        });
    }

    private function penaltyEvents(Loan $loan): Collection
    {
        $schedules = LoanSchedule::where('loan_id', $loan->id)
            ->where('penalty_due', '>', 0)
            ->orderBy('due_date')
            ->get();

        return $schedules->map(function (LoanSchedule $s) {
            $penaltyFormatted = TenantMoney::format((float) $s->penalty_due);
            $daysOverdue = $s->days_overdue;

            return [
                'type' => 'penalty_assessed',
                'title' => 'Penalty Assessed',
                'description' => "{$penaltyFormatted} penalty applied on installment #{$s->installment_no} ({$daysOverdue} days overdue).",
                'amount' => TenantMoney::format((float) $s->penalty_due),
                'actor' => null,
                'notes' => null,
                'timestamp' => $s->due_date->startOfDay(),
            ];
        });
    }

    private function rescheduleEvents(Loan $loan): Collection
    {
        $reschedules = LoanReschedule::with('performedBy')
            ->where('original_loan_id', $loan->id)
            ->orderBy('reschedule_date')
            ->get();

        return $reschedules->map(function (LoanReschedule $r) {
            $actor = $r->performedBy ? $this->staffSummary($r->performedBy) : null;
            $typeLabel = str_replace('_', ' ', ucwords($r->reschedule_type, '_'));

            return [
                'type' => 'rescheduled',
                'title' => 'Loan Rescheduled',
                'description' => "Loan rescheduled ({$typeLabel}). New term: {$r->new_duration} months, New rate: {$r->new_rate}%",
                'amount' => null,
                'actor' => $actor,
                'notes' => $r->reason,
                'timestamp' => $r->created_at,
            ];
        });
    }

    // ─── Helpers provided by FormatsLoanActivity trait ────────────────────────
}
