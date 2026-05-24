<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $parentOutstandingAmountBeforeTopup = null;

        if ($this->relationLoaded('parentLoan') && $this->parentLoan) {
            $parentOutstandingAmountBeforeTopup = (float) (LoanSchedule::query()
                ->where('loan_id', $this->parentLoan->id)
                ->where('status', '!=', 'paid')
                ->selectRaw('COALESCE(SUM(
                    (COALESCE(principal_due, 0) + COALESCE(interest_due, 0) + COALESCE(charges_due, 0) + COALESCE(penalty_due, 0))
                    -
                    (COALESCE(principal_paid, 0) + COALESCE(interest_paid, 0) + COALESCE(charges_paid, 0) + COALESCE(penalty_paid, 0))
                ), 0) as outstanding_total')
                ->value('outstanding_total'));
        }

        $totalOutstandingAmount = $this->getTotalOutstandingAmount();

        $totalExpectedAmount = (float) (LoanSchedule::query()
            ->where('loan_id', $this->id)
            ->where('status', '!=', 'superseded')
            ->selectRaw('COALESCE(SUM(COALESCE(principal_due, 0) + COALESCE(interest_due, 0) + COALESCE(charges_due, 0) + COALESCE(penalty_due, 0)), 0) as total')
            ->value('total'));

        $totalPaidAmount = (float) (LoanSchedule::query()
            ->where('loan_id', $this->id)
            ->where('status', '!=', 'superseded')
            ->selectRaw('COALESCE(SUM(COALESCE(principal_paid, 0) + COALESCE(interest_paid, 0) + COALESCE(charges_paid, 0) + COALESCE(penalty_paid, 0)), 0) as total')
            ->value('total'));

        return [
            'id' => $this->id,
            'loan_no' => $this->loan_no,
            'loan_application_id' => $this->loan_application_id,
            'total_paid' => $totalPaidAmount,
            'total_expected' => $totalExpectedAmount,

            // Parties
            'member_id' => $this->member_id,
            'loan_product_id' => $this->loan_product_id,
            'branch_id' => $this->branch_id,
            'loan_officer_id' => $this->loan_officer_id,
            'approved_by' => $this->approved_by,
            'disbursed_by' => $this->disbursed_by,

            // Amounts
            'principal' => $this->principal,
            'principal_formatted' => TenantMoney::format($this->principal),
            'processing_fee' => $this->processing_fee,
            'processing_fee_formatted' => TenantMoney::format($this->processing_fee),
            'total_charges_deducted' => $this->total_charges_deducted,
            'total_charges_deducted_formatted' => TenantMoney::format($this->total_charges_deducted),
            'net_disbursed_amount' => $this->net_disbursed_amount,
            'net_disbursed_amount_formatted' => TenantMoney::format($this->net_disbursed_amount),
            'outstanding_balance' => $this->outstanding_balance,
            'outstanding_balance_formatted' => TenantMoney::format($this->outstanding_balance),
            'total_outstanding' => $totalOutstandingAmount,
            'total_outstanding_formatted' => TenantMoney::format($totalOutstandingAmount),

            // Terms
            'interest_rate' => $this->interest_rate,
            'term_months' => $this->term_months,

            // Disbursement
            'disbursed_at' => $this->disbursed_at?->format('Y-m-d'),
            'schedule_date' => $this->schedule_date?->format('Y-m-d') ?? $this->loanApplication?->schedule_date?->format('Y-m-d'),
            'approved_at' => $this->loanApplication?->approved_at?->format('Y-m-d'),
            'disbursement_method' => $this->disbursement_method,
            'disbursement_reference' => $this->disbursement_reference,
            'charge_deduction_mode' => $this->charge_deduction_mode,
            'charge_receipt_no' => $this->charge_receipt_no,
            'savings_account_id' => $this->savings_account_id,
            'mobile_money_provider' => $this->mobile_money_provider,
            'mobile_money_number' => $this->mobile_money_number,
            'is_rescheduled' => (bool) $this->is_rescheduled,
            'reschedule_count' => (int) ($this->reschedule_count ?? 0),
            'original_term_months' => $this->original_term_months,
            'original_interest_rate' => $this->original_interest_rate,

            // Status
            'status' => $this->status,
            'status_label' => $this->status?->label(),
            'notes' => $this->notes,
            'currency_code' => TenantMoney::code(),

            // Relations
            'loan_product' => $this->whenLoaded('loanProduct', fn () => [
                'id' => $this->loanProduct->id,
                'name' => $this->loanProduct->name,
                'code' => $this->loanProduct->code,
                'interest_method' => $this->loanProduct->interest_method,
                'repayment_cycle' => $this->loanProduct->repayment_cycle,
                'grace_period' => (int) ($this->loanProduct->grace_period ?? 0),
                'penalty_type' => $this->loanProduct->penalty_type,
                'penalty_rate' => $this->loanProduct->penalty_rate,
                'penalty_grace_days' => $this->loanProduct->penalty_grace_days,
                'penalty_rules' => $this->loanProduct->relationLoaded('penaltyRules')
                    ? $this->loanProduct->penaltyRules->map(fn ($r) => [
                        'id' => $r->id,
                        'system_type' => $r->system_type,
                        'penalty_type' => $r->penalty_type,
                        'penalty_rate' => $r->penalty_rate,
                        'grace_days' => $r->grace_days,
                        'amount' => $r->amount,
                        'applies_to' => $r->applies_to,
                    ])
                    : [],
                'charges' => $this->loanProduct->relationLoaded('charges')
                    ? $this->loanProduct->charges->map(fn ($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'charge_type' => $c->charge_type,
                        'value' => $c->value,
                        'frequency' => $c->frequency,
                        'application_timing' => $c->application_timing, // This might be used if we want to know when it applies
                    ])
                    : [],
            ]),
            'member' => $this->whenLoaded('member', fn () => [
                'id' => $this->member->id,
                'name' => $this->member->name,
                'member_number' => $this->member->member_number ?? $this->member->code ?? null,
            ]),
            'loan_officer' => $this->whenLoaded('loanOfficer', fn () => $this->loanOfficer ? [
                'id' => $this->loanOfficer->id,
                'name' => $this->loanOfficer->name,
            ] : null),
            'disbursed_by_staff' => $this->whenLoaded('disbursedBy', fn () => $this->disbursedBy ? [
                'id' => $this->disbursedBy->id,
                'name' => $this->disbursedBy->name,
            ] : null),
            'schedules' => $this->whenLoaded('schedules', fn () => $this->schedules->map(fn ($s) => [
                'id' => $s->id,
                'installment_no' => $s->installment_no,
                'due_date' => $s->due_date?->format('Y-m-d'),
                'principal_due' => $s->principal_due,
                'interest_due' => $s->interest_due,
                'total_due' => $s->total_due,
                'principal_due_formatted' => TenantMoney::format($s->principal_due),
                'interest_due_formatted' => TenantMoney::format($s->interest_due),
                'total_due_formatted' => TenantMoney::format($s->total_due),
                'principal_paid' => $s->principal_paid,
                'interest_paid' => $s->interest_paid,
                'outstanding_balance' => $s->outstanding_balance,
                'status' => $s->status,
            ])),

            'applied_charges' => $this->whenLoaded('appliedCharges', fn () => $this->appliedCharges->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'charge_type' => $c->charge_type,
                'application_timing' => $c->application_timing,
                'charge_amount' => $c->charge_amount,
                'charge_amount_formatted' => TenantMoney::format($c->charge_amount),
                'used_amount' => $c->used_amount,
                'remaining_amount' => $c->remaining_amount,
                'is_waived' => $c->is_waived,
                'is_mandatory' => $c->is_mandatory,
                'waiver_reason' => $c->waiver_reason,
            ])),

            // Parent loan info (for top-ups/restructuring)
            'parent_loan_id' => $this->parent_loan_id,
            'parent_loan' => $this->whenLoaded('parentLoan', fn () => [
                'id' => $this->parentLoan->id,
                'loan_no' => $this->parentLoan->loan_no,
                'principal_formatted' => TenantMoney::format($this->parentLoan->principal),
                'outstanding_balance_formatted' => TenantMoney::format($this->parentLoan->outstanding_balance),
                'outstanding_amount_before_topup' => $parentOutstandingAmountBeforeTopup,
                'outstanding_amount_before_topup_formatted' => TenantMoney::format($parentOutstandingAmountBeforeTopup),
                'status' => $this->parentLoan->status,
                'status_label' => $this->parentLoan->status?->label(),
                'disbursed_at' => $this->parentLoan->disbursed_at?->format('Y-m-d'),
            ]),

            // Warnings (e.g. savings fallback)
            'disbursement_warnings' => $this->disbursement_warnings ?? [],
        ];
    }
}
