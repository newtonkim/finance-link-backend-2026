<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Support\BranchContext;
use App\Tenant\Modules\Accounting\Contracts\IncomeStatementServiceInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Support\IncomeStatementLines;
use App\Tenant\Modules\Settings\Models\CurrencySetting;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncomeStatementService implements IncomeStatementServiceInterface
{
    /** Every amount is a signed contribution to surplus: credit minus debit. */
    public function generate(Carbon $from, Carbon $to, ?Carbon $compareFrom = null, ?Carbon $compareTo = null, bool $hideZero = true, ?int $branchId = null): array
    {
        $compareFrom ??= $from->copy()->subYearNoOverflow();
        $compareTo ??= $to->copy()->subYearNoOverflow();
        $currentQuery = $this->movements($from, $to, false, $branchId);
        $compareQuery = $this->movements($compareFrom, $compareTo, false, $branchId);
        $this->assertCurrency($currentQuery);
        $this->assertCurrency($compareQuery);
        $closingQuery = $this->movements($from, $to, true, $branchId);
        $compareClosingQuery = $this->movements($compareFrom, $compareTo, true, $branchId);
        $this->assertCurrency($closingQuery);
        $this->assertCurrency($compareClosingQuery);
        $current = $this->aggregate($currentQuery);
        $compare = $this->aggregate($compareQuery);
        $accounts = ChartOfAccount::withTrashed()->orderBy('gl_code')->get()->keyBy('id');
        $definitions = IncomeStatementLines::DEFINITIONS + [
            'unclassified_income' => ['Unclassified income', 'INCOME'],
            'unclassified_expenses' => ['Unclassified expenses', 'EXPENSE'],
        ];
        $sections = [];
        foreach ($definitions as $key => [$label]) {
            $sections[$key] = ['key' => $key, 'label' => $label, 'kind' => 'section', 'amount' => '0.00', 'compare_amount' => '0.00', 'accounts' => []];
        }
        $issues = [];
        $mapping = [];
        foreach ($accounts as $account) {
            if (! in_array($account->account_type, ['INCOME', 'EXPENSE'], true)) {
                continue;
            }
            $amount = $current[$account->id] ?? '0.00';
            $comparison = $compare[$account->id] ?? '0.00';
            $cursor = $account;
            $seen = [];
            $line = null;
            $problem = null;
            while ($cursor) {
                if (isset($seen[$cursor->id])) {
                    $problem = 'Account hierarchy contains a cycle.';
                    break;
                }
                $seen[$cursor->id] = true;
                if ($cursor->account_type !== $account->account_type) {
                    $problem = 'Parent has a different account type.';
                    break;
                }
                if ($cursor->income_statement_line !== null && $line === null) {
                    if (! in_array($cursor->income_statement_line, IncomeStatementLines::keysFor($account->account_type), true)) {
                        $problem = 'Report mapping is incompatible with the account type.';
                        break;
                    }
                    $line = $cursor->income_statement_line;
                }
                if ($cursor->parent_id && ! isset($accounts[$cursor->parent_id])) {
                    $problem = 'Parent account is missing.';
                    break;
                }
                $cursor = $cursor->parent_id ? $accounts[$cursor->parent_id] : null;
            }
            if ($problem) {
                $line = null;
            }
            $mapping[] = [$account->id, $account->parent_id, $account->income_statement_line, $line];
            $hasMovement = isset($current[$account->id]) || isset($compare[$account->id]);
            if ($hasMovement && ($line === null || $problem || ! $account->is_postable)) {
                $issues[] = ['account_id' => $account->id, 'gl_code' => $account->gl_code, 'name' => $account->name,
                    'message' => $problem ?? ($line === null ? 'No income statement mapping. Included in unclassified amounts.' : 'Movement posted directly to a header account.')];
            }
            $line ??= $account->account_type === 'INCOME' ? 'unclassified_income' : 'unclassified_expenses';
            $sections[$line]['amount'] = bcadd($sections[$line]['amount'], $amount, 2);
            $sections[$line]['compare_amount'] = bcadd($sections[$line]['compare_amount'], $comparison, 2);
            if (! $hideZero || bccomp($amount, '0', 2) !== 0 || bccomp($comparison, '0', 2) !== 0) {
                // Only own movement: accounts and headers are never counted a second time.
                if ($hasMovement || $account->is_postable) {
                    $sections[$line]['accounts'][] = ['id' => $account->id, 'gl_code' => $account->gl_code,
                        'name' => $account->name, 'amount' => $amount, 'compare_amount' => $comparison];
                }
            }
        }
        $rows = [];
        $subtotal = function (string $key, string $label, array $keys) use (&$rows, $sections) {
            $row = ['key' => $key, 'label' => $label, 'kind' => 'subtotal', 'amount' => '0.00', 'compare_amount' => '0.00', 'accounts' => []];
            foreach ($keys as $section) {
                foreach (['amount', 'compare_amount'] as $field) {
                    $row[$field] = bcadd($row[$field], $sections[$section][$field], 2);
                }
            }
            $rows[] = $row;
        };
        $included = [];
        foreach (array_keys($definitions) as $key) {
            $rows[] = $sections[$key];
            $included[] = $key;
            if ($key === 'interest_expense') {
                $subtotal('net_interest', 'Net interest income', $included);
            } elseif ($key === 'other_income') {
                $subtotal('operating_income', 'Operating income before impairment', $included);
            } elseif ($key === 'other_expenses') {
                $subtotal('operating_surplus', 'Operating surplus / (deficit)', $included);
            } elseif ($key === 'investment_income') {
                $subtotal('before_tax', 'Surplus / (deficit) before tax — classified accounts', $included);
            }
        }
        $subtotal('surplus', 'Surplus / (deficit) for the period', $included);
        $last = end($rows);
        $ledgerCurrent = $this->sum($current);
        $ledgerCompare = $this->sum($compare);

        return [
            'entity' => app()->bound('currentTenant') ? app('currentTenant')->name : 'SACCO',
            'currency' => 'UGX',
            'basis' => 'Management report from posted ledger movements. Loan interest follows existing product posting policies, including upfront flat-rate interest; this is not a certification of accrual or IFRS compliance.',
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'compare_from' => $compareFrom->toDateString(), 'compare_to' => $compareTo->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'scope' => $this->scope($branchId), 'mapping_version' => hash('sha256', json_encode($mapping)),
            'rows' => $rows,
            'totals' => ['surplus' => $last['amount'], 'compare_surplus' => $last['compare_amount']],
            'diagnostics' => [
                'issues' => $issues, 'classification_complete' => $issues === [],
                'has_movements' => $current !== [] || $compare !== [],
                'ledger_surplus' => $ledgerCurrent, 'compare_ledger_surplus' => $ledgerCompare,
                'difference' => bcsub($last['amount'], $ledgerCurrent, 2),
                'compare_difference' => bcsub($last['compare_amount'], $ledgerCompare, 2),
                'closing_transfers_excluded' => $this->sum($this->aggregate($closingQuery)),
                'compare_closing_transfers_excluded' => $this->sum($this->aggregate($compareClosingQuery)),
            ],
        ];
    }

    public function ledger(int $accountId, Carbon $from, Carbon $to, int $page = 1, ?int $branchId = null): array
    {
        $account = ChartOfAccount::withTrashed()->whereIn('account_type', ['INCOME', 'EXPENSE'])->findOrFail($accountId);
        $query = $this->movements($from, $to, false, $branchId)->where('gl.account_id', $account->id);
        $this->assertCurrency($query);
        $total = (clone $query)->count();
        $rows = (clone $query)->select('gl.*', 'je.entry_no')->orderBy('gl.date')->orderBy('gl.id')->forPage($page, 50)->get();
        $running = '0.00';
        if ($first = $rows->first()) {
            $prior = (clone $query)->where(function ($q) use ($first) {
                $q->where('gl.date', '<', $first->date)->orWhere(function ($q) use ($first) {
                    $q->where('gl.date', $first->date)->where('gl.id', '<', $first->id);
                });
            });
            $running = $this->sum($this->aggregate($prior));
        }
        $data = $rows->map(function ($row) use (&$running) {
            $running = bcadd($running, bcsub((string) $row->credit, (string) $row->debit, 2), 2);

            return ['id' => $row->id, 'date' => $row->date, 'entry_no' => $row->entry_no, 'description' => $row->narration,
                'debit' => bcadd((string) $row->debit, '0', 2), 'credit' => bcadd((string) $row->credit, '0', 2), 'running_movement' => $running];
        })->all();

        return ['data' => $data, 'current_page' => $page, 'last_page' => max(1, (int) ceil($total / 50)), 'total' => $total, 'per_page' => 50];
    }

    private function scope(?int $branchId): array
    {
        $staff = BranchContext::getStaff();
        abort_unless($staff, 403, 'Staff access is required.');
        $all = BranchContext::scopeFor($staff) === BranchContext::SCOPE_ALL;
        $allowed = BranchContext::allowedBranchIds();
        if ($branchId !== null) {
            abort_unless(BranchContext::branchExists($branchId) && ($all || in_array($branchId, $allowed, true)), 403, 'You cannot access this branch.');

            return ['all_branches' => false, 'branch_ids' => [$branchId], 'selected_branch_id' => $branchId];
        }

        return ['all_branches' => $all, 'branch_ids' => $all ? [] : $allowed, 'selected_branch_id' => null];
    }

    private function movements(Carbon $from, Carbon $to, bool $closing = false, ?int $branchId = null): Builder
    {
        $scope = $this->scope($branchId);
        $query = DB::connection('tenant')->table('general_ledger as gl')
            ->join('journal_entries as je', 'je.id', '=', 'gl.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'gl.account_id')
            ->whereIn('coa.account_type', ['INCOME', 'EXPENSE'])
            ->whereIn('je.status', ['posted', 'reversed'])
            ->where('je.is_closing_entry', $closing)
            ->whereBetween('gl.date', [$from->toDateString(), $to->toDateString()]);
        if (! $scope['all_branches']) {
            $query->whereIn('je.branch_id', $scope['branch_ids']);
        }

        return $query;
    }

    private function assertCurrency(Builder $query): void
    {
        $currency = CurrencySetting::query()->value('default_currency') ?? 'UGX';
        if ($currency !== 'UGX' || (clone $query)->where(function ($q) {
            $q->whereNull('je.currency_code')->orWhere('je.currency_code', '!=', 'UGX');
        })->exists()) {
            throw ValidationException::withMessages(['currency' => 'This report requires UGX ledger amounts. Foreign-currency conversion has not been established; correct the currency configuration or postings first.']);
        }
    }

    private function aggregate(Builder $query): array
    {
        return (clone $query)->select('gl.account_id')->selectRaw('SUM(gl.credit - gl.debit) AS amount')
            ->groupBy('gl.account_id')->get()->mapWithKeys(fn ($row) => [$row->account_id => bcadd((string) $row->amount, '0', 2)])->all();
    }

    private function sum(array $values): string
    {
        return array_reduce($values, fn ($sum, $value) => bcadd($sum, $value, 2), '0.00');
    }
}
