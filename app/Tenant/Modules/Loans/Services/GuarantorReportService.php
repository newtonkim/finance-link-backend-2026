<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Data\GuarantorRules;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Support\TenantMoney;
use Illuminate\Support\Facades\DB;

/**
 * Guarantor reports for staff: how exposed each guarantor is, requests still waiting
 * for an answer, and guarantors who have asked to be replaced.
 */
class GuarantorReportService
{
    public function __construct(
        protected GuarantorArrearsService $arrears,
    ) {}

    /**
     * Every member or group guaranteeing something now: how much they have pledged,
     * how much of their savings that holds, and how many of their loans are overdue.
     * Most held first.
     *
     * @return list<array<string, mixed>>
     */
    public function exposure(): array
    {
        $rules = GuarantorRules::for();
        $binding = $rules->consentRequired
            ? [LoanApplicationGuarantor::STATUS_ACCEPTED, LoanApplicationGuarantor::STATUS_LOCKED]
            : LoanApplicationGuarantor::ACTIVE_STATUSES;

        $pledges = LoanApplicationGuarantor::query()
            ->whereIn('status', LoanApplicationGuarantor::ACTIVE_STATUSES)
            ->whereHas('loanApplication', fn ($q) => $q->whereNotIn('status', [
                LoanApplication::STATUS_REJECTED,
                LoanApplication::STATUS_CANCELLED,
            ]))
            ->with(['member', 'group'])
            ->get(['id', 'guarantor_type', 'guarantor_id', 'status', 'guarantee_amount', 'loan_id', 'release_requested_at']);

        $overdueLoanIds = $this->arrears->overdueLoans()->keys()->all();
        $recovered = LoanApplicationGuarantor::query()
            ->where('recovered_amount', '>', 0)
            ->selectRaw('guarantor_type, guarantor_id, SUM(recovered_amount) as total')
            ->groupBy('guarantor_type', 'guarantor_id')
            ->get()
            ->mapWithKeys(fn ($r) => ["{$r->guarantor_type}:{$r->guarantor_id}" => (float) $r->total]);

        $grouped = $pledges->groupBy(fn ($p) => "{$p->guarantor_type}:{$p->guarantor_id}");
        $memberIds = $pledges->where('guarantor_type', LoanApplicationGuarantor::TYPE_INDIVIDUAL)->pluck('guarantor_id')->unique();
        $groupIds = $pledges->where('guarantor_type', LoanApplicationGuarantor::TYPE_GROUP)->pluck('guarantor_id')->unique();

        $savings = [
            ...DB::connection('tenant')->table('savings_accounts')
                ->whereIn('member_id', $memberIds)->whereNull('deleted_at')->where('status', '!=', 'closed')
                ->selectRaw('member_id, SUM(balance) as total')->groupBy('member_id')
                ->pluck('total', 'member_id')->mapWithKeys(fn ($v, $k) => ["individual:{$k}" => (float) $v])->all(),
            ...DB::connection('tenant')->table('group_savings_accounts')
                ->whereIn('savings_group_id', $groupIds)->whereNull('deleted_at')->where('status', '!=', 'closed')
                ->selectRaw('savings_group_id, SUM(balance) as total')->groupBy('savings_group_id')
                ->pluck('total', 'savings_group_id')->mapWithKeys(fn ($v, $k) => ["group:{$k}" => (float) $v])->all(),
        ];

        return $grouped->map(function ($rows, $key) use ($binding, $overdueLoanIds, $recovered, $savings, $rules) {
            $first = $rows->first();
            $pledged = (float) $rows->sum('guarantee_amount');
            $held = $rules->holdSavings ? (float) $rows->whereIn('status', $binding)->sum('guarantee_amount') : 0.0;
            $balance = $savings[$key] ?? 0.0;

            return [
                'guarantor_type' => $first->guarantor_type,
                'guarantor_id' => $first->guarantor_id,
                'name' => $first->guarantorName(),
                'code' => $first->guarantor_type === LoanApplicationGuarantor::TYPE_GROUP ? $first->group?->code : $first->member?->code,
                'guarantees' => $rows->count(),
                'pledged_amount' => round($pledged, 2),
                'held_amount' => round($held, 2),
                'held_amount_formatted' => TenantMoney::format($held),
                'savings_balance' => round($balance, 2),
                'held_share' => $balance > 0 ? round($held / $balance * 100, 1) : null,
                'overdue_loans' => $rows->whereNotNull('loan_id')->whereIn('loan_id', $overdueLoanIds)->count(),
                'recovered_to_date' => round($recovered[$key] ?? 0.0, 2),
                'release_requested' => $rows->whereNotNull('release_requested_at')->isNotEmpty(),
            ];
        })->sortByDesc('held_amount')->values()->all();
    }

    /** Guarantors who have been asked to accept and have not answered yet, soonest deadline first. */
    public function pendingConsents(): array
    {
        return LoanApplicationGuarantor::query()
            ->where('status', LoanApplicationGuarantor::STATUS_REQUESTED)
            ->with(['member', 'group', 'loanApplication.member', 'loan'])
            ->orderBy('consent_expires_at')
            ->get()
            ->map(fn (LoanApplicationGuarantor $g) => [
                'id' => $g->id,
                'loan_application_id' => $g->loan_application_id,
                'application_no' => $g->loanApplication?->application_no,
                'loan_id' => $g->loan_id,
                'loan_no' => $g->loan?->loan_no,
                'borrower_name' => $g->loanApplication?->member?->name,
                'guarantor_type' => $g->guarantor_type,
                'name' => $g->guarantorName(),
                'guarantee_amount' => (float) $g->guarantee_amount,
                'guarantee_amount_formatted' => TenantMoney::format($g->guarantee_amount),
                'requested_at' => $g->requested_at,
                'consent_expires_at' => $g->consent_expires_at,
                'is_replacement' => (bool) $g->substitutes_id,
            ])->values()->all();
    }

    /** Guarantors on running loans who have asked to be replaced. */
    public function releaseRequests(): array
    {
        return LoanApplicationGuarantor::query()
            ->where('status', LoanApplicationGuarantor::STATUS_LOCKED)
            ->whereNotNull('release_requested_at')
            ->with(['member', 'group', 'loanApplication.member', 'loan'])
            ->orderBy('release_requested_at')
            ->get()
            ->map(function (LoanApplicationGuarantor $g) {
                $replacement = LoanApplicationGuarantor::query()
                    ->where('substitutes_id', $g->id)
                    ->whereIn('status', LoanApplicationGuarantor::AWAITING_RESPONSE_STATUSES)
                    ->first();

                return [
                    'id' => $g->id,
                    'loan_id' => $g->loan_id,
                    'loan_no' => $g->loan?->loan_no,
                    'loan_application_id' => $g->loan_application_id,
                    'borrower_name' => $g->loanApplication?->member?->name,
                    'guarantor_type' => $g->guarantor_type,
                    'name' => $g->guarantorName(),
                    'guarantee_amount' => (float) $g->guarantee_amount,
                    'guarantee_amount_formatted' => TenantMoney::format($g->guarantee_amount),
                    'release_requested_at' => $g->release_requested_at,
                    'release_request_reason' => $g->release_request_reason,
                    'replacement_pending' => $replacement ? $replacement->guarantorName() : null,
                ];
            })->values()->all();
    }
}
