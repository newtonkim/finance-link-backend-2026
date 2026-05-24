<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Contracts\TrialBalanceServiceInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\GeneralLedger;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrialBalanceService implements TrialBalanceServiceInterface
{
    private const PER_PAGE = 50;

    public function asOfDate(Carbon $date): array
    {
        $movements = $this->aggregateGl(null, $date);
        $accounts  = $this->buildRows(new Collection(), $movements);
        $totals    = $this->calcTotals($accounts, 'as_of_date');

        return [
            'mode'         => 'as_of_date',
            'date'         => $date->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'totals'       => $totals,
            'accounts'     => $accounts->values()->all(),
        ];
    }

    public function forPeriod(Carbon $from, Carbon $to): array
    {
        $opening  = $this->aggregateGl(null, $from->copy()->subDay());
        $period   = $this->aggregateGl($from, $to);
        $accounts = $this->buildRows($opening, $period);
        $totals   = $this->calcTotals($accounts, 'period');

        return [
            'mode'         => 'period',
            'from'         => $from->toDateString(),
            'to'           => $to->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'totals'       => $totals,
            'accounts'     => $accounts->values()->all(),
        ];
    }

    public function ledgerLines(int $accountId, Carbon $from, Carbon $to, int $page = 1): LengthAwarePaginator
    {
        $query = GeneralLedger::on('tenant')
            ->with('journalEntry')
            ->where('account_id', $accountId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->orderBy('id');

        $total  = $query->count();
        $offset = ($page - 1) * self::PER_PAGE;
        $rows   = $query->skip($offset)->take(self::PER_PAGE)->get();

        $openingBalance = (string) (GeneralLedger::on('tenant')
            ->where('account_id', $accountId)
            ->where('date', '<', $from->toDateString())
            ->orderByDesc('id')
            ->value('balance') ?? '0');

        $account       = ChartOfAccount::on('tenant')->find($accountId);
        $normalBalance = $account?->normal_balance ?? 'DR';
        $running       = $openingBalance;

        $items = $rows->map(function ($gl) use (&$running, $normalBalance) {
            $debit  = (string) $gl->debit;
            $credit = (string) $gl->credit;
            $running = $normalBalance === 'DR'
                ? bcadd($running, bcsub($debit, $credit, 4), 4)
                : bcadd($running, bcsub($credit, $debit, 4), 4);

            $je = $gl->journalEntry;

            return [
                'date'            => $gl->date?->toDateString(),
                'entry_no'        => $je?->entry_no,
                'journal_type'    => $je?->journal_type,
                'description'     => $gl->narration,
                'debit'           => (float) $gl->debit,
                'credit'          => (float) $gl->credit,
                'running_balance' => (float) $running,
                'entity_type'     => null,
                'entity_id'       => null,
            ];
        });

        return new LengthAwarePaginator(
            $items->all(), $total, self::PER_PAGE, $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }

    private function aggregateGl(?Carbon $from, Carbon $to): Collection
    {
        $query = DB::connection('tenant')
            ->table('general_ledger')
            ->select(
                'account_id',
                DB::raw('SUM(debit) as total_debit'),
                DB::raw('SUM(credit) as total_credit')
            )
            ->where('date', '<=', $to->toDateString())
            ->groupBy('account_id');

        if ($from) {
            $query->where('date', '>=', $from->toDateString());
        }

        return $query->get()->keyBy('account_id');
    }

    private function buildRows(Collection $opening, Collection $period): Collection
    {
        $allAccounts = ChartOfAccount::on('tenant')
            ->orderBy('gl_code')
            ->get();

        return $allAccounts->map(function (ChartOfAccount $account) use ($opening, $period) {
            $open = $opening->get($account->id);
            $per  = $period->get($account->id);

            $openDr  = (float) ($open?->total_debit  ?? 0);
            $openCr  = (float) ($open?->total_credit ?? 0);
            $perDr   = (float) ($per?->total_debit   ?? 0);
            $perCr   = (float) ($per?->total_credit  ?? 0);

            $closeDr = $openDr + $perDr;
            $closeCr = $openCr + $perCr;

            return [
                'id'              => $account->id,
                'gl_code'         => $account->gl_code,
                'name'            => $account->name,
                'account_type'    => $account->account_type,
                'account_subtype' => $account->account_subtype,
                'level'           => $account->level,
                'is_postable'     => (bool) $account->is_postable,
                'opening_debit'   => $openDr,
                'opening_credit'  => $openCr,
                'period_debit'    => $perDr,
                'period_credit'   => $perCr,
                'closing_debit'   => $closeDr,
                'closing_credit'  => $closeCr,
            ];
        });
    }

    private function calcTotals(Collection $accounts, string $mode): array
    {
        $postable = $accounts->where('is_postable', true);

        $openDr  = $postable->sum('opening_debit');
        $openCr  = $postable->sum('opening_credit');
        $perDr   = $postable->sum('period_debit');
        $perCr   = $postable->sum('period_credit');
        $closeDr = $postable->sum('closing_debit');
        $closeCr = $postable->sum('closing_credit');

        return [
            'total_opening_debit'  => $openDr,
            'total_opening_credit' => $openCr,
            'total_period_debit'   => $perDr,
            'total_period_credit'  => $perCr,
            'total_closing_debit'  => $closeDr,
            'total_closing_credit' => $closeCr,
            'is_balanced'          => number_format($closeDr, 2) === number_format($closeCr, 2),
        ];
    }
}
