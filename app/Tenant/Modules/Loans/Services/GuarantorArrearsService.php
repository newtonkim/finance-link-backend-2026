<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Data\GuarantorRules;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Support\TenantMoney;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Warns guarantors when the loan they stand behind falls into arrears, and lists
 * overdue loans with their guarantors for staff.
 *
 * Days overdue are counted the way the aging report counts them: from the oldest
 * unpaid instalment's due date, less the product's grace period. A spell of arrears
 * starts at that due date, so a loan that catches up and later falls behind again
 * warns its guarantors again.
 */
class GuarantorArrearsService
{
    /** Loan statuses that can be in arrears and still have guarantees locked to them. */
    private const OPEN_LOAN_STATUSES = ['disbursed', 'active', 'arrears', 'written_off'];

    public function __construct(
        protected GuarantorNotifier $notifier,
    ) {}

    /**
     * Overdue loans that have guarantees locked to them, keyed by loan id.
     *
     * @return Collection<int, array{loan_id: int, loan_no: string, borrower_name: ?string, member_id: int, days_past_due: int, arrears_amount: float, arrears_since: string}>
     */
    public function overdueLoans(?int $loanId = null, ?string $asOf = null): Collection
    {
        $asOf ??= now()->toDateString();

        return DB::connection('tenant')->table('loans as l')
            ->join('loan_repayment_schedule as rs', 'rs.loan_id', '=', 'l.id')
            ->leftJoin('loan_products as p', 'p.id', '=', 'l.loan_product_id')
            ->leftJoin('members as m', 'm.id', '=', 'l.member_id')
            ->whereIn('l.status', self::OPEN_LOAN_STATUSES)
            ->whereNull('l.deleted_at')
            ->where('rs.due_date', '<', $asOf)
            ->where('rs.status', '!=', 'paid')
            ->whereRaw('(rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                        > (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid)')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('loan_application_guarantors as g')
                    ->whereColumn('g.loan_id', 'l.id')
                    ->where('g.status', LoanApplicationGuarantor::STATUS_LOCKED)
                    ->whereNull('g.deleted_at');
            })
            ->when($loanId, fn ($q) => $q->where('l.id', $loanId))
            ->groupBy('l.id', 'l.loan_no', 'm.name', 'l.member_id', 'p.grace_period')
            ->selectRaw('l.id as loan_id, l.loan_no, m.name as borrower_name, l.member_id')
            ->selectRaw('MIN(rs.due_date) as arrears_since')
            ->selectRaw('DATEDIFF(?, MIN(rs.due_date)) - COALESCE(p.grace_period, 0) as days_past_due', [$asOf])
            ->selectRaw('SUM((rs.principal_due + rs.interest_due + rs.charges_due + rs.penalty_due)
                           - (rs.principal_paid + rs.interest_paid + rs.charges_paid + rs.penalty_paid)) as arrears_amount')
            ->get()
            ->filter(fn ($row) => (int) $row->days_past_due > 0)
            ->mapWithKeys(fn ($row) => [(int) $row->loan_id => [
                'loan_id' => (int) $row->loan_id,
                'loan_no' => (string) $row->loan_no,
                'borrower_name' => $row->borrower_name,
                'member_id' => (int) $row->member_id,
                'days_past_due' => (int) $row->days_past_due,
                'arrears_amount' => round((float) $row->arrears_amount, 2),
                'arrears_since' => (string) $row->arrears_since,
            ]]);
    }

    /**
     * Warn every guarantor who is due a warning under the arrears settings.
     * Returns how many guarantees were warned.
     *
     * @param  string|null  $subdomain  the tenant, when running from a scheduled command
     */
    public function notifyDue(?string $subdomain = null): int
    {
        $rules = GuarantorRules::for();

        if ($rules->arrearsNoticeDays <= 0) {
            return 0;
        }

        $notified = 0;

        foreach ($this->overdueLoans() as $arrears) {
            if ($arrears['days_past_due'] < $rules->arrearsNoticeDays) {
                continue;
            }

            foreach ($this->lockedGuarantees($arrears['loan_id']) as $pledge) {
                if ($this->isDue($pledge, $arrears, $rules)) {
                    $this->warn($pledge, $arrears, $subdomain);
                    $notified++;
                }
            }
        }

        return $notified;
    }

    /**
     * Warn a loan's guarantors now, whatever the settings say, for staff who want to
     * chase a loan early. Returns how many guarantees were warned.
     *
     * @throws ValidationException when the loan is not overdue or has no locked guarantees
     */
    public function notifyLoan(Loan $loan): int
    {
        $arrears = $this->overdueLoans($loan->id)->get($loan->id);

        if (! $arrears) {
            throw ValidationException::withMessages([
                'loan' => ['This loan is not overdue, or has no guarantees standing behind it.'],
            ]);
        }

        $pledges = $this->lockedGuarantees($loan->id);
        foreach ($pledges as $pledge) {
            $this->warn($pledge, $arrears);
        }

        return $pledges->count();
    }

    /**
     * Overdue loans with their guarantors, for the staff watch list.
     *
     * @return list<array<string, mixed>>
     */
    public function watchList(): array
    {
        $loans = $this->overdueLoans()->sortByDesc('days_past_due');
        $pledges = LoanApplicationGuarantor::query()
            ->whereIn('loan_id', $loans->keys())
            ->where('status', LoanApplicationGuarantor::STATUS_LOCKED)
            ->with(['member', 'group'])
            ->get()
            ->groupBy('loan_id');

        return $loans->map(fn (array $loan) => [
            ...$loan,
            'arrears_amount_formatted' => TenantMoney::format($loan['arrears_amount']),
            'guaranteed_amount' => round((float) ($pledges[$loan['loan_id']] ?? collect())->sum('guarantee_amount'), 2),
            'guarantors' => ($pledges[$loan['loan_id']] ?? collect())->map(fn (LoanApplicationGuarantor $g) => [
                'id' => $g->id,
                'guarantor_type' => $g->guarantor_type,
                'guarantor_id' => $g->guarantor_id,
                'name' => $g->guarantorName(),
                'guarantee_amount' => (float) $g->guarantee_amount,
                'guarantee_amount_formatted' => TenantMoney::format($g->guarantee_amount),
                'arrears_notified_at' => $g->arrears_notified_at,
                'arrears_notice_count' => $g->arrears_notice_count,
            ])->values(),
        ])->values()->all();
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function lockedGuarantees(int $loanId): Collection
    {
        return LoanApplicationGuarantor::query()
            ->where('loan_id', $loanId)
            ->where('status', LoanApplicationGuarantor::STATUS_LOCKED)
            ->get();
    }

    /**
     * Due when never warned in this spell of arrears, or when reminders are on and
     * the last warning is old enough.
     */
    private function isDue(LoanApplicationGuarantor $pledge, array $arrears, GuarantorRules $rules): bool
    {
        $last = $pledge->arrears_notified_at;

        if (! $last || $last->lt(Carbon::parse($arrears['arrears_since']))) {
            return true;
        }

        return $rules->arrearsReminderDays > 0
            && $last->lte(now()->subDays($rules->arrearsReminderDays));
    }

    private function warn(LoanApplicationGuarantor $pledge, array $arrears, ?string $subdomain = null): void
    {
        DB::connection('tenant')->transaction(function () use ($pledge, $arrears, $subdomain) {
            $pledge->forceFill([
                'arrears_notified_at' => now(),
                'arrears_notice_count' => (int) $pledge->arrears_notice_count + 1,
            ])->save();

            $this->notifier->arrears($pledge, $arrears, $subdomain);
        });
    }
}
