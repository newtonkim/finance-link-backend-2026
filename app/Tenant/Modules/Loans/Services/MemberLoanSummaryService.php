<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Member;
use App\Tenant\Modules\Loans\Contracts\MemberLoanSummaryServiceInterface;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Support\TenantMoney;

class MemberLoanSummaryService implements MemberLoanSummaryServiceInterface
{
    public function summarize(Member $member): array
    {
        $activeLoans = $this->activeLoans($member->id);
        $arrearsData = $this->arrearsData($member->id);
        $repaymentSnap = $this->repaymentSnapshot($member->id);
        $savingsTotal = (float) $member->savingsAccounts()->sum('balance');
        $shareCapital = (float) $member->shares()->sum('total_value');

        return [
            'active_loans' => [
                'count' => $activeLoans['count'],
                'total_outstanding' => $activeLoans['total_outstanding'],
                'total_outstanding_formatted' => TenantMoney::format($activeLoans['total_outstanding']),
            ],
            'arrears' => [
                'total_amount' => $arrearsData['total_amount'],
                'total_amount_formatted' => TenantMoney::format($arrearsData['total_amount']),
                'days_in_arrears' => $arrearsData['max_days'],
            ],
            'repayment_history' => [
                'total_installments' => $repaymentSnap['total'],
                'paid_on_time' => $repaymentSnap['on_time'],
                'missed' => $repaymentSnap['missed'],
                'on_time_percentage' => $repaymentSnap['on_time_pct'],
            ],
            'savings_balance' => $savingsTotal,
            'savings_balance_formatted' => TenantMoney::format($savingsTotal),
            'share_capital' => $shareCapital,
            'share_capital_formatted' => TenantMoney::format($shareCapital),
            'currency_code' => TenantMoney::code(),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function activeLoans(int $memberId): array
    {
        $loans = Loan::where('member_id', $memberId)
            ->whereIn('status', [LoanStatus::Disbursed, LoanStatus::Active, LoanStatus::Arrears])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(outstanding_balance), 0) as total')
            ->first();

        return [
            'count' => (int) ($loans->cnt ?? 0),
            'total_outstanding' => (float) ($loans->total ?? 0),
        ];
    }

    private function arrearsData(int $memberId): array
    {
        $today = now()->toDateString();

        $rows = LoanSchedule::whereHas('loan', fn ($q) => $q->where('member_id', $memberId)
            ->whereIn('status', [LoanStatus::Disbursed, LoanStatus::Active, LoanStatus::Arrears])
        )
            ->where('due_date', '<', $today)
            ->where('status', '!=', 'paid')
            ->selectRaw('
            COALESCE(SUM(total_due - principal_paid - interest_paid), 0) as arrears_amount,
            COALESCE(MAX(CURRENT_DATE - due_date), 0)                   as max_days
        ')
            ->first();

        return [
            'total_amount' => (float) ($rows->arrears_amount ?? 0),
            'max_days' => (int) ($rows->max_days ?? 0),
        ];
    }

    private function repaymentSnapshot(int $memberId): array
    {
        $today = now()->toDateString();

        // Only consider past-due installments (status is settled by payment)
        $schedules = LoanSchedule::whereHas('loan', fn ($q) => $q->where('member_id', $memberId)
        )
            ->where('due_date', '<', $today)
            ->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'paid' AND paid_at <= due_date THEN 1 ELSE 0 END) as on_time,
            SUM(CASE WHEN status != 'paid' THEN 1 ELSE 0 END) as missed
        ")
            ->first();

        $total = (int) ($schedules->total ?? 0);
        $onTime = (int) ($schedules->on_time ?? 0);
        $missed = (int) ($schedules->missed ?? 0);

        return [
            'total' => $total,
            'on_time' => $onTime,
            'missed' => $missed,
            'on_time_pct' => $total > 0 ? round(($onTime / $total) * 100, 1) : null,
        ];
    }
}
