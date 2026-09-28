<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Concerns\ScopesLedgerReports;
use App\Tenant\Modules\Accounting\Contracts\CashFlowStatementServiceInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Support\CashFlowLines;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Statement of cash flows, direct method, from posted ledger entries.
 *
 * Cash is every account in the Cash & Cash Equivalents group (or with a Cash or
 * Bank subtype). For each posted entry that moves cash, each of its other lines
 * shows exactly how much cash came in (a credit) or went out (a debit) and why, so
 * the statement always adds up to the change in cash: opening cash + net cash flow
 * = closing cash. Transfers between cash accounts cancel out, and entries that move
 * no cash (a loan repaid from savings, depreciation) are not cash flows at all.
 */
class CashFlowStatementService implements CashFlowStatementServiceInterface
{
    use ScopesLedgerReports;

    private const STATUSES = ['posted', 'reversed'];

    public function generate(Carbon $from, Carbon $to, ?Carbon $compareFrom = null, ?Carbon $compareTo = null, bool $hideZero = true, ?int $branchId = null): array
    {
        $compareFrom ??= $from->copy()->subYearNoOverflow();
        $compareTo ??= $to->copy()->subYearNoOverflow();
        $scope = $this->scope($branchId);

        $accounts = ChartOfAccount::withTrashed()->orderBy('gl_code')->get()->keyBy('id');
        $cashIds = $this->cashAccountIds($accounts);

        $this->assertCurrency($this->postedLines($scope)->whereIn('gl.account_id', $cashIds ?: [0])
            ->whereBetween('gl.date', [min($from, $compareFrom)->toDateString(), max($to, $compareTo)->toDateString()]));

        $current = $this->flows($from, $to, $cashIds, $scope);
        $compare = $this->flows($compareFrom, $compareTo, $cashIds, $scope);

        [$categories, $issues] = $this->classify($accounts, $cashIds, array_unique([
            ...array_keys($current['by_account']), ...array_keys($compare['by_account']),
        ]));

        $sections = $this->sections($accounts, $categories, $current['by_account'], $compare['by_account'], $hideZero);
        $totals = array_column($sections, null, 'key');

        $cash = $this->cashPosition($accounts, $cashIds, $from, $to, $scope);
        $compareCash = $this->cashPosition($accounts, $cashIds, $compareFrom, $compareTo, $scope);

        $net = $this->netOf($sections, 'total');
        $compareNet = $this->netOf($sections, 'compare_total');

        return [
            'entity' => app()->bound('currentTenant') ? app('currentTenant')->name : 'SACCO',
            'currency' => 'UGX',
            'method' => 'direct',
            'basis' => 'Direct method from posted ledger entries. Loans to members and member savings are operating activities, as IAS 7 sets out for financial institutions. Repayments and transfers that move no cash (for example a loan repaid from savings) are not cash flows.',
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'compare_from' => $compareFrom->toDateString(), 'compare_to' => $compareTo->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'scope' => $scope,
            'sections' => array_values(array_filter($sections, fn ($s) => $s['key'] !== 'other'
                || bccomp($s['total'], '0', 2) !== 0 || bccomp($s['compare_total'], '0', 2) !== 0)),
            'summary' => [
                'opening_cash' => $cash['opening'], 'closing_cash' => $cash['closing'], 'net_change' => $net,
                'operating' => $totals['operating']['total'], 'investing' => $totals['investing']['total'],
                'financing' => $totals['financing']['total'], 'other' => $totals['other']['total'],
                'cash_in' => $current['cash_in'], 'cash_out' => $current['cash_out'],
                'compare_opening_cash' => $compareCash['opening'], 'compare_closing_cash' => $compareCash['closing'],
                'compare_net_change' => $compareNet,
                'compare_operating' => $totals['operating']['compare_total'], 'compare_investing' => $totals['investing']['compare_total'],
                'compare_financing' => $totals['financing']['compare_total'], 'compare_other' => $totals['other']['compare_total'],
            ],
            'cash_accounts' => $cash['accounts'],
            'monthly' => $this->monthly($from, $to, $cash['opening'], $current['by_month'], $categories),
            'diagnostics' => [
                'issues' => $issues,
                'classification_complete' => $issues === [],
                'has_movements' => $current['by_account'] !== [] || $compare['by_account'] !== [],
                'cash_account_count' => count($cashIds),
                // Opening + net cash flow must equal closing cash. A difference means an
                // entry that moved cash does not balance.
                'difference' => bcsub(bcsub($cash['closing'], $cash['opening'], 2), $net, 2),
                'compare_difference' => bcsub(bcsub($compareCash['closing'], $compareCash['opening'], 2), $compareNet, 2),
            ],
        ];
    }

    public function ledger(int $accountId, Carbon $from, Carbon $to, int $page = 1, ?int $branchId = null): array
    {
        $scope = $this->scope($branchId);
        $account = ChartOfAccount::withTrashed()->findOrFail($accountId);
        $cashIds = $this->cashAccountIds(ChartOfAccount::withTrashed()->get()->keyBy('id'));

        $cashEntries = $this->postedLines($scope)
            ->whereIn('gl.account_id', $cashIds ?: [0])
            ->whereBetween('gl.date', [$from->toDateString(), $to->toDateString()])
            ->select('gl.journal_entry_id');

        $query = DB::connection('tenant')->table('general_ledger as gl')
            ->join('journal_entries as je', 'je.id', '=', 'gl.journal_entry_id')
            ->whereIn('gl.journal_entry_id', $cashEntries)
            ->where('gl.account_id', $account->id);

        $total = (clone $query)->count();
        $rows = $query->select('gl.id', 'gl.date', 'gl.debit', 'gl.credit', 'gl.narration', 'je.entry_no', 'je.narration as entry_narration')
            ->orderBy('gl.date')->orderBy('gl.id')
            ->forPage($page, 50)
            ->get();

        return [
            'account' => ['id' => $account->id, 'gl_code' => $account->gl_code, 'name' => $account->name],
            'data' => $rows->map(function ($row) {
                $net = bcsub((string) $row->credit, (string) $row->debit, 2);

                return [
                    'id' => $row->id, 'date' => $row->date, 'entry_no' => $row->entry_no,
                    'description' => $row->narration ?: $row->entry_narration,
                    'cash_in' => bccomp($net, '0', 2) > 0 ? $net : '0.00',
                    'cash_out' => bccomp($net, '0', 2) < 0 ? bcsub('0', $net, 2) : '0.00',
                ];
            })->all(),
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / 50)),
            'total' => $total,
            'per_page' => 50,
        ];
    }

    // ─── Cash accounts ────────────────────────────────────────────────────────

    /** @return list<int> */
    private function cashAccountIds(Collection $accounts): array
    {
        return $accounts->filter(function (ChartOfAccount $account) use ($accounts) {
            if ($account->account_type !== 'ASSET') {
                return false;
            }
            if (in_array($account->account_subtype, CashFlowLines::CASH_SUBTYPES, true)) {
                return true;
            }
            $seen = [];
            for ($cursor = $account; $cursor && ! isset($seen[$cursor->id]); $cursor = $accounts[$cursor->parent_id] ?? null) {
                $seen[$cursor->id] = true;
                if ($cursor->gl_code === CashFlowLines::CASH_GROUP_GL_CODE) {
                    return true;
                }
            }

            return false;
        })->keys()->all();
    }

    /** Opening and closing cash, in total and per cash account. */
    private function cashPosition(Collection $accounts, array $cashIds, Carbon $from, Carbon $to, array $scope): array
    {
        $balances = fn (string $operator, Carbon $date) => $this->postedLines($scope)
            ->whereIn('gl.account_id', $cashIds ?: [0])
            ->where('gl.date', $operator, $date->toDateString())
            ->groupBy('gl.account_id')
            ->select('gl.account_id')->selectRaw('SUM(gl.debit - gl.credit) AS balance')
            ->pluck('balance', 'account_id')
            ->map(fn ($v) => bcadd((string) $v, '0', 2));

        $opening = $balances('<', $from);
        $closing = $balances('<=', $to);

        $rows = [];
        foreach ($cashIds as $id) {
            $o = $opening[$id] ?? '0.00';
            $c = $closing[$id] ?? '0.00';
            $account = $accounts[$id];
            if (bccomp($o, '0', 2) === 0 && bccomp($c, '0', 2) === 0 && ! $account->is_postable) {
                continue;
            }
            $rows[] = ['id' => $id, 'gl_code' => $account->gl_code, 'name' => $account->name,
                'opening' => $o, 'closing' => $c, 'change' => bcsub($c, $o, 2)];
        }

        return [
            'opening' => $this->sum($opening->all()),
            'closing' => $this->sum($closing->all()),
            'accounts' => $rows,
        ];
    }

    // ─── Cash flows ───────────────────────────────────────────────────────────

    /**
     * Cash in and out by account, from the non-cash lines of every entry that moved
     * cash in the period. A credit on such a line is cash received for that account's
     * purpose; a debit is cash paid out.
     */
    private function flows(Carbon $from, Carbon $to, array $cashIds, array $scope): array
    {
        if ($cashIds === []) {
            return ['by_account' => [], 'by_month' => [], 'cash_in' => '0.00', 'cash_out' => '0.00'];
        }

        $cashEntries = $this->postedLines($scope)
            ->whereIn('gl.account_id', $cashIds)
            ->whereBetween('gl.date', [$from->toDateString(), $to->toDateString()])
            ->select('gl.journal_entry_id');

        $rows = DB::connection('tenant')->table('general_ledger as gl')
            ->join('journal_entries as je', 'je.id', '=', 'gl.journal_entry_id')
            ->whereIn('gl.journal_entry_id', $cashEntries)
            ->whereNotIn('gl.account_id', $cashIds)
            ->groupBy('gl.account_id', 'je.date')
            ->select('gl.account_id', 'je.date')
            ->selectRaw('SUM(CASE WHEN gl.credit > gl.debit THEN gl.credit - gl.debit ELSE 0 END) AS inflow')
            ->selectRaw('SUM(CASE WHEN gl.debit > gl.credit THEN gl.debit - gl.credit ELSE 0 END) AS outflow')
            ->get();

        $byAccount = [];
        $byMonth = [];
        $in = '0.00';
        $out = '0.00';
        foreach ($rows as $row) {
            $inflow = bcadd((string) $row->inflow, '0', 2);
            $outflow = bcadd((string) $row->outflow, '0', 2);
            $byAccount[$row->account_id] ??= ['in' => '0.00', 'out' => '0.00'];
            $byAccount[$row->account_id]['in'] = bcadd($byAccount[$row->account_id]['in'], $inflow, 2);
            $byAccount[$row->account_id]['out'] = bcadd($byAccount[$row->account_id]['out'], $outflow, 2);

            $month = Carbon::parse($row->date)->format('Y-m');
            $byMonth[$month][$row->account_id] = bcadd($byMonth[$month][$row->account_id] ?? '0.00', bcsub($inflow, $outflow, 2), 2);

            $in = bcadd($in, $inflow, 2);
            $out = bcadd($out, $outflow, 2);
        }

        return ['by_account' => $byAccount, 'by_month' => $byMonth, 'cash_in' => $in, 'cash_out' => $out];
    }

    /**
     * The category of each account that took part in a cash entry: its own code or
     * subtype, or failing that the nearest ancestor's. Accounts that match nothing
     * go to other operating cash flows and are reported as issues.
     *
     * @return array{0: array<int, string>, 1: list<array>}
     */
    private function classify(Collection $accounts, array $cashIds, array $accountIds): array
    {
        $categories = [];
        $issues = [];

        foreach ($accountIds as $id) {
            $account = $accounts[$id] ?? null;
            $category = null;
            $problem = null;
            $seen = [];

            for ($cursor = $account; $cursor; $cursor = $cursor->parent_id ? ($accounts[$cursor->parent_id] ?? null) : null) {
                if (isset($seen[$cursor->id])) {
                    $problem = 'Account hierarchy contains a cycle.';
                    break;
                }
                $seen[$cursor->id] = true;
                $category = $this->categoryOf($cursor);
                if ($category) {
                    break;
                }
            }

            if (! $category) {
                $issues[] = [
                    'account_id' => (int) $id,
                    'gl_code' => $account?->gl_code,
                    'name' => $account?->name ?? 'Unknown account',
                    'message' => $problem ?? 'Not classified for the cash flow statement. Included in other operating cash flows.',
                ];
                $category = 'other_operating';
            }

            $categories[$id] = $category;
        }

        return [$categories, $issues];
    }

    private function categoryOf(ChartOfAccount $account): ?string
    {
        if (isset(CashFlowLines::BY_GL_CODE[$account->gl_code])) {
            return CashFlowLines::BY_GL_CODE[$account->gl_code];
        }
        if (in_array($account->account_type, ['INCOME', 'EXPENSE'], true)) {
            return CashFlowLines::BY_INCOME_STATEMENT_LINE[$account->income_statement_line] ?? null;
        }

        return CashFlowLines::BY_SUBTYPE[$account->account_subtype] ?? null;
    }

    private function sections(Collection $accounts, array $categories, array $current, array $compare, bool $hideZero): array
    {
        $sections = [];
        foreach (CashFlowLines::SECTIONS as $key => $label) {
            $sections[$key] = ['key' => $key, 'label' => $label, 'total' => '0.00', 'compare_total' => '0.00', 'rows' => []];
        }

        foreach (CashFlowLines::ROWS as $key => [$section, $category, $direction, $label, $hint]) {
            $row = ['key' => $key, 'label' => $label, 'hint' => $hint, 'direction' => $direction,
                'amount' => '0.00', 'compare_amount' => '0.00', 'accounts' => []];

            foreach ($categories as $id => $accountCategory) {
                if ($accountCategory !== $category) {
                    continue;
                }
                $amount = $this->directed($current[$id] ?? null, $direction);
                $comparison = $this->directed($compare[$id] ?? null, $direction);
                if (bccomp($amount, '0', 2) === 0 && bccomp($comparison, '0', 2) === 0) {
                    continue;
                }
                $account = $accounts[$id] ?? null;
                $row['accounts'][] = ['id' => (int) $id, 'gl_code' => $account?->gl_code, 'name' => $account?->name,
                    'amount' => $amount, 'compare_amount' => $comparison];
                $row['amount'] = bcadd($row['amount'], $amount, 2);
                $row['compare_amount'] = bcadd($row['compare_amount'], $comparison, 2);
            }

            usort($row['accounts'], fn ($a, $b) => strcmp((string) $a['gl_code'], (string) $b['gl_code']));
            $sections[$section]['total'] = bcadd($sections[$section]['total'], $row['amount'], 2);
            $sections[$section]['compare_total'] = bcadd($sections[$section]['compare_total'], $row['compare_amount'], 2);

            if (! $hideZero || $row['accounts'] !== []) {
                $sections[$section]['rows'][] = $row;
            }
        }

        return array_values($sections);
    }

    /** An account's cash flow as shown on a row: money in, money out (negative), or both. */
    private function directed(?array $flow, string $direction): string
    {
        if (! $flow) {
            return '0.00';
        }

        return match ($direction) {
            'in' => $flow['in'],
            'out' => bcsub('0', $flow['out'], 2),
            default => bcsub($flow['in'], $flow['out'], 2),
        };
    }

    /** Net cash flow for each month of the period, by section, with cash at month end. */
    private function monthly(Carbon $from, Carbon $to, string $opening, array $byMonth, array $categories): array
    {
        $sectionOf = [];
        foreach (CashFlowLines::ROWS as [$section, $category]) {
            $sectionOf[$category] = $section;
        }

        $months = [];
        $cash = $opening;
        foreach (CarbonPeriod::create($from->copy()->startOfMonth(), '1 month', $to->copy()->startOfMonth()) as $month) {
            $key = $month->format('Y-m');
            $row = ['month' => $key, 'operating' => '0.00', 'investing' => '0.00', 'financing' => '0.00', 'other' => '0.00'];
            foreach ($byMonth[$key] ?? [] as $accountId => $amount) {
                $section = $sectionOf[$categories[$accountId] ?? 'other_operating'];
                $row[$section] = bcadd($row[$section], $amount, 2);
            }
            $row['net'] = $this->sum([$row['operating'], $row['investing'], $row['financing'], $row['other']]);
            $cash = bcadd($cash, $row['net'], 2);
            $row['closing_cash'] = $cash;
            $months[] = $row;
        }

        return $months;
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Posted ledger lines the signed-in staff member may see. */
    private function postedLines(array $scope): Builder
    {
        $query = DB::connection('tenant')->table('general_ledger as gl')
            ->join('journal_entries as je', 'je.id', '=', 'gl.journal_entry_id')
            ->whereIn('je.status', self::STATUSES);

        if (! $scope['all_branches']) {
            $query->whereIn('je.branch_id', $scope['branch_ids'] ?: [0]);
        }

        return $query;
    }

    private function netOf(array $sections, string $field): string
    {
        return $this->sum(array_column($sections, $field));
    }

    private function sum(array $values): string
    {
        return array_reduce($values, fn ($sum, $value) => bcadd($sum, (string) $value, 2), '0.00');
    }
}
