<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Services\MemebersSettingSevices\FindsettingsAction;
use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

function groupsIbelong($memberId)
{
    $mid = (int) $memberId;

    return DB::table('savings_group_members')
        ->join('savings_groups as sg', 'savings_group_members.savings_group_id', '=', 'sg.id')
        ->whereRaw('member_id=?', [$memberId])
        ->select([
            DB::raw("(SELECT SUM(loans.balance) FROM loan_applications
                     INNER JOIN loans ON loan_applications.id=loans.loan_application_id
                     WHERE loan_applications.member_id={$mid} AND loans.status!='closed') as total_loan_balance"),
            'sg.id',
            'sg.name',
            'sg.code',
        ])
        ->get();
}
function pickallmemeberthesgroups($memberId)
{
    // dont  do  what you think
    $pickMemberGroups = DB::table('savings_group_members')
        ->whereRaw('member_id=?', [$memberId])
        ->pluck('savings_group_id');
    $allMemberIshareGroups = DB::table('savings_group_members as sgm')
        ->whereIn('sgm.savings_group_id', $pickMemberGroups)
        ->join('savings_groups as sg', 'sgm.savings_group_id', '=', 'sg.id')
        ->join('members as mb', 'sgm.member_id', '=', 'mb.id')
        // ->leftJoin('loans as ls', 'sgm.member_id', '=', 'ls.member_id')
        ->select([
            'sg.id',
            'mb.id as member_id',
            'mb.name as member_name',
            'mb.code as member_code',
            'sg.name as group_name',
            'sg.code as group_code',
            // 'ls.balance as total_loan_balance',
            // DB::raw('IF(ls.status="closed",1,0) as member_has_active_loan'),
            // 'ls.id As loan_id',
            // 'ls.loan_no As loan_code',
            DB::raw('(SELECT JSON_ARRAYAGG(JSON_OBJECT("loan_id", id, "loan_code", loan_no, "member_has_active_loan", status)) FROM loans WHERE member_id=mb.id && status!="closed") as loandetails'),
        ])
        ->groupBy(
            'sg.id',
            'mb.id',
            'mb.name',
            'mb.code',
            'sg.name',
            'sg.code',
            // 'ls.id'
        )
        ->get();
    foreach ($allMemberIshareGroups as $key => $value) {
        $value->loandetails = json_decode($value->loandetails, true);
    }

    return $allMemberIshareGroups;
}

class LoanApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // ** chcek if member exists in any group(s) *//

        $FindsettingsAction = new FindsettingsAction(['loan-application']);
        // $FindsettingsAction->settingsCollection(['loan-application']);
        $canBeGuaranteedByOtherGroup = $FindsettingsAction->saccoLoanMemberOnLoanApplicationCanBeGuaranteedByOtherGroup();

        return [
            'id' => $this->id,
            'application_no' => $this->application_no,
            'member_id' => $this->member_id,
            'member' => $this->whenLoaded('member', fn () => [
                'id' => $this->member->id,
                'name' => $this->member->name,
                'member_no' => $this->member->code,
            ]),
            'group_memberships' => ! $canBeGuaranteedByOtherGroup ? groupsIbelong($this->member_id) : 'allowed_to_be_guaranteed_by_other_groups',
            'my_groups_member' => pickallmemeberthesgroups($this->member_id),
            'loan_guarantors' => DB::table('loan_application_guarantors')
                ->leftJoin('members as m', function ($join) {
                    $join->on('loan_application_guarantors.guarantor_id', '=', 'm.id')
                        ->where('loan_application_guarantors.guarantor_type', '=', 'individual');
                })
                ->leftJoin('savings_groups as sg', function ($join) {
                    $join->on('loan_application_guarantors.guarantor_id', '=', 'sg.id')
                        ->where('loan_application_guarantors.guarantor_type', '=', 'group');
                })

                ->whereRaw('loan_application_id=?', [$this->id])
                ->get([
                    'guarantee_amount AS contribution',
                    DB::raw('IFNULL(loan_application_guarantors.id, null) as guarantors_id'),
                    DB::raw('IFNULL(m.name, sg.name) as name'),
                    DB::raw('IFNULL(m.code, sg.code) as code'),
                    'loan_application_guarantors.guarantor_type AS type',
                ]),
            'loan_product_id' => $this->loan_product_id,
            'loan_product' => $this->whenLoaded('loanProduct', fn () => new LoanProductListResource($this->loanProduct)),
            'branch_id' => $this->branch_id,
            'loan_officer_id' => $this->loan_officer_id,
            'loan_officer' => $this->whenLoaded('loanOfficer', fn () => $this->staffSummary($this->loanOfficer)),
            'requested_amount' => $this->requested_amount,
            'requested_amount_formatted' => TenantMoney::format($this->requested_amount),
            'requested_term' => $this->requested_term,
            'purpose' => $this->purpose,
            'repayment_source' => $this->repayment_source,
            'status' => $this->status,
            'recommended_amount' => $this->recommended_amount,
            'recommended_amount_formatted' => TenantMoney::format($this->recommended_amount),
            'recommended_term' => $this->recommended_term,
            'recommended_interest_rate' => $this->recommended_interest_rate,
            'approved_amount' => $this->approved_amount,
            'approved_amount_formatted' => TenantMoney::format($this->approved_amount),
            'approved_term' => $this->approved_term,
            'approved_interest_rate' => $this->approved_interest_rate,
            'rejection_reason' => $this->rejection_reason,
            'appraisal_notes' => $this->appraisal_notes,
            'risk_rating' => $this->risk_rating,
            'approval_notes' => $this->approval_notes,
            'appraised_by' => $this->whenLoaded('appraisedBy', fn () => $this->staffSummary($this->appraisedBy)),
            'recommended_by' => $this->whenLoaded('recommendedBy', fn () => $this->staffSummary($this->recommendedBy)),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->staffSummary($this->approvedBy)),
            'rejected_by' => $this->whenLoaded('rejectedBy', fn () => $this->staffSummary($this->rejectedBy)),
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at,
            'return_reason' => $this->return_reason,
            'returned_at' => $this->returned_at,
            'submitted_at' => $this->submitted_at,
            'reviewed_at' => $this->reviewed_at,
            'recommended_at' => $this->recommended_at,
            'approved_at' => $this->approved_at,
            'rejected_at' => $this->rejected_at,
            'disbursed_at' => $this->disbursed_at,
            'disbursed_loan_id' => $this->disbursed_loan_id,
            'disbursed_loan' => $this->whenLoaded('disbursedLoan', fn () => new LoanResource($this->disbursedLoan)),
            'product_charges' => $this->whenLoaded('loanProduct', fn () => $this->resolveProductCharges()),
            'approvals' => LoanApplicationApprovalResource::collection(
                $this->whenLoaded('approvals')
            ),
            'status_history' => LoanApplicationStatusHistoryResource::collection(
                $this->whenLoaded('statusHistory')
            ),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->staffSummary($this->createdBy)),
            'currency_code' => TenantMoney::code(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Resolve product charges with computed amounts against the effective principal.
     * Returns processing fee + all loan_product_charge rows as a unified list.
     */
    private function resolveProductCharges(): array
    {
        $product = $this->loanProduct;
        $principal = (float) ($this->approved_amount ?? $this->recommended_amount ?? $this->requested_amount ?? 0);
        $charges = [];

        // Processing fee (product-level flat field)
        $feeType = $product->processing_fee_type ?? 'none';
        $feeValue = (float) ($product->processing_fee_value ?? 0);
        if ($feeType !== 'none' && $feeValue > 0) {
            $feeAmount = $feeType === 'percentage'
                ? round($principal * $feeValue / 100, 2)
                : round($feeValue, 2);
            $charges[] = [
                'id' => 'processing_fee',
                'name' => 'Processing Fee',
                'charge_type' => $feeType,
                'value' => $feeValue,
                'computed_amount' => $feeAmount,
                'computed_amount_formatted' => TenantMoney::format($feeAmount),
                'application_timing' => 'on_disbursement',
                'category' => 'processing_fee',
                'is_mandatory' => true,
            ];
        }

        // Product-linked loan charges (via loan_product_charge pivot)
        if ($product->relationLoaded('charges')) {
            foreach ($product->charges as $charge) {
                $computedAmount = $charge->charge_type === 'percentage'
                    ? round($principal * (float) $charge->value / 100, 2)
                    : round((float) $charge->value, 2);

                $charges[] = [
                    'id' => $charge->id,
                    'name' => $charge->name,
                    'charge_type' => $charge->charge_type,
                    'value' => $charge->value,
                    'computed_amount' => $computedAmount,
                    'computed_amount_formatted' => TenantMoney::format($computedAmount),
                    'application_timing' => $this->getChargeTiming($charge->category ?? 'other'),
                    'category' => $charge->category,
                    'is_mandatory' => true,
                    'description' => $charge->description,
                ];
            }
        }

        // Net disbursement summary
        $onDisbursementTotal = collect($charges)
            ->where('application_timing', 'on_disbursement')
            ->sum('computed_amount');

        return [
            'items' => $charges,
            'summary' => [
                'gross_amount' => $principal,
                'gross_amount_formatted' => TenantMoney::format($principal),
                'total_on_disbursement' => round($onDisbursementTotal, 2),
                'total_on_disbursement_formatted' => TenantMoney::format($onDisbursementTotal),
                'net_disbursed' => round($principal - $onDisbursementTotal, 2),
                'net_disbursed_formatted' => TenantMoney::format($principal - $onDisbursementTotal),
            ],
        ];
    }

    /**
     * Map database categories to frontend timing keywords.
     */
    private function getChargeTiming(?string $category): string
    {
        return match ($category) {
            'processing_fee', 'disbursement_fee', 'appraisal_fee', 'insurance', 'other' => 'on_disbursement',
            'penalty', 'late_fee' => 'on_repayment',
            default => 'on_disbursement',
        };
    }

    private function staffSummary($staff): ?array
    {
        if (! $staff) {
            return null;
        }

        return [
            'id' => $staff->id,
            'name' => $staff->name,
        ];
    }
}
