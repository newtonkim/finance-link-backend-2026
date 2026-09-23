<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Contracts\BalanceSheetServiceInterface;
use App\Tenant\Modules\Accounting\Contracts\TrialBalanceServiceInterface;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Settings\Models\FinancialYear;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BalanceSheetService implements BalanceSheetServiceInterface
{
    /** account_type => [section key, label, sign applied to (debit − credit)] */
    private const SECTIONS = [
        'ASSET' => ['assets', 'Assets', 1],
        'LIABILITY' => ['liabilities', 'Liabilities', -1],
        'EQUITY' => ['equity', "Equity / Members' Funds", -1],
    ];

    public const CURRENT_YEAR_LABEL = 'Surplus / (Deficit) – Current Year';

    public const PRIOR_YEARS_LABEL = 'Retained Surplus – Prior Years (unclosed)';

    public function __construct(
        private readonly TrialBalanceServiceInterface $trialBalance,
    ) {}

    public function generate(Carbon $asAt, ?Carbon $compareTo = null, bool $hideZero = true): array
    {
        $compareTo ??= $this->defaultCompareDate($asAt);

        $current = $this->netBalances($asAt);
        $compare = $this->netBalances($compareTo);

        $accounts = ChartOfAccount::on('tenant')
            ->whereIn('account_type', array_keys(self::SECTIONS))
            ->orderBy('gl_code')
            ->get();

        $sections = [];
        foreach (self::SECTIONS as $type => [$key, $label, $sign]) {
            $typeAccounts = $accounts->where('account_type', $type)->values();
            $virtual = $type === 'EQUITY'
                ? $this->surplusLines($typeAccounts, $asAt, $compareTo)
                : [];

            $lines = $this->buildTree($typeAccounts, $sign, $current, $compare, $virtual);
            if ($hideZero) {
                $lines = $this->prune($lines);
            }

            $sections[] = [
                'key' => $key,
                'label' => $label,
                'total' => round(array_sum(array_column($lines, 'amount')), 2),
                'compare_total' => round(array_sum(array_column($lines, 'compare_amount')), 2),
                'lines' => $lines,
            ];
        }

        $financialYear = $this->financialYearFor($asAt);

        return [
            'as_at' => $asAt->toDateString(),
            'compare_to' => $compareTo->toDateString(),
            'financial_year' => $financialYear ? [
                'name' => $financialYear->name,
                'start_date' => Carbon::parse($financialYear->start_date)->toDateString(),
                'end_date' => Carbon::parse($financialYear->end_date)->toDateString(),
            ] : null,
            'generated_at' => now()->toIso8601String(),
            'sections' => $sections,
            'totals' => [
                'current' => $this->totals($sections, 'total'),
                'compare' => $this->totals($sections, 'compare_total'),
            ],
        ];
    }

    public function defaultCompareDate(Carbon $asAt): Carbon
    {
        $financialYear = $this->financialYearFor($asAt);

        return $financialYear
            ? Carbon::parse($financialYear->start_date)->subDay()->startOfDay()
            : Carbon::create($asAt->year - 1, 12, 31)->startOfDay();
    }

    /** @return array<int, float> account_id => debit − credit (cumulative to $date) */
    private function netBalances(Carbon $date): array
    {
        $rows = $this->trialBalance->asOfDate($date)['accounts'];

        $net = [];
        foreach ($rows as $row) {
            $net[$row['id']] = (float) $row['closing_debit'] - (float) $row['closing_credit'];
        }

        return $net;
    }

    private function financialYearFor(Carbon $date): ?FinancialYear
    {
        return FinancialYear::on('tenant')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->orderByDesc('start_date')
            ->first();
    }

    private function financialYearStart(Carbon $date): Carbon
    {
        $financialYear = $this->financialYearFor($date);

        return $financialYear
            ? Carbon::parse($financialYear->start_date)->startOfDay()
            : Carbon::create($date->year, 1, 1)->startOfDay();
    }

    /** Σ(credit − debit) over INCOME + EXPENSE accounts = income − expenses. */
    private function surplus(?Carbon $from, Carbon $to): float
    {
        $query = DB::connection('tenant')
            ->table('general_ledger as gl')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'gl.account_id')
            ->whereIn('coa.account_type', ['INCOME', 'EXPENSE'])
            ->where('gl.date', '<=', $to->toDateString());

        if ($from) {
            $query->where('gl.date', '>=', $from->toDateString());
        }

        return (float) $query->sum(DB::raw('gl.credit - gl.debit'));
    }

    /**
     * Two computed lines. They sit under the Retained Earnings group, falling back
     * to the equity level-1 header, then to the section root.
     */
    private function surplusLines(Collection $equityAccounts, Carbon $asAt, Carbon $compareTo): array
    {
        $parent = $equityAccounts->first(fn ($a) => $a->account_subtype === 'Retained Earnings' && (int) $a->level === 2)
            ?? $equityAccounts->first(fn ($a) => (int) $a->level === 1 && ! $a->is_postable);

        $split = function (Carbon $date): array {
            $fyStart = $this->financialYearStart($date);

            return [
                'current' => $this->surplus($fyStart, $date),
                'prior' => $this->surplus(null, $fyStart->copy()->subDay()),
            ];
        };

        $now = $split($asAt);
        $then = $split($compareTo);

        return [
            $this->virtualNode('computed:current-year', self::CURRENT_YEAR_LABEL, $parent?->id, $now['current'], $then['current']),
            $this->virtualNode('computed:prior-years', self::PRIOR_YEARS_LABEL, $parent?->id, $now['prior'], $then['prior']),
        ];
    }

    private function virtualNode(string $key, string $name, ?int $parentId, float $amount, float $compare): array
    {
        return [
            'key' => $key, 'parent_id' => $parentId,
            'id' => null, 'gl_code' => null, 'name' => $name, 'level' => null,
            'is_postable' => false, 'is_computed' => true,
            'own' => $amount, 'own_compare' => $compare,
        ];
    }

    private function buildTree(Collection $accounts, int $sign, array $current, array $compare, array $virtual): array
    {
        $ids = array_flip($accounts->pluck('id')->all());
        $children = [];
        $roots = [];

        $nodes = $accounts->map(fn (ChartOfAccount $a) => [
            'key' => $a->id, 'parent_id' => $a->parent_id,
            'id' => $a->id, 'gl_code' => $a->gl_code, 'name' => $a->name, 'level' => (int) $a->level,
            'is_postable' => (bool) $a->is_postable, 'is_computed' => false,
            // Own balance for every account (even headers) so nothing is lost if a header was posted to.
            'own' => $sign * ($current[$a->id] ?? 0.0),
            'own_compare' => $sign * ($compare[$a->id] ?? 0.0),
        ])->all();

        foreach ([...$nodes, ...$virtual] as $node) {
            if ($node['parent_id'] !== null && isset($ids[$node['parent_id']])) {
                $children[$node['parent_id']][] = $node;
            } else {
                $roots[] = $node;
            }
        }

        // Level-1 headers (ASSETS, LIABILITIES, EQUITY) are the section itself: promote their children.
        $top = [];
        foreach ($roots as $root) {
            if (! $root['is_computed'] && ! $root['is_postable'] && $root['level'] === 1) {
                array_push($top, ...($children[$root['key']] ?? []));
                // Keep any stray balance posted directly to the header.
                if (round($root['own'], 2) != 0 || round($root['own_compare'], 2) != 0) {
                    // Children were promoted above; do not count them again with this header.
                    $root['key'] = 'header-own:'.$root['key'];
                    $top[] = $root;
                }
            } else {
                $top[] = $root;
            }
        }

        return array_map(fn (array $node) => $this->resolve($node, $children), $top);
    }

    private function resolve(array $node, array $children): array
    {
        $kids = array_map(
            fn (array $child) => $this->resolve($child, $children),
            $node['is_computed'] ? [] : ($children[$node['key']] ?? []),
        );

        return [
            'id' => $node['id'],
            'gl_code' => $node['gl_code'],
            'name' => $node['name'],
            'level' => $node['level'],
            'is_postable' => $node['is_postable'],
            'is_computed' => $node['is_computed'],
            'amount' => $node['own'] + array_sum(array_column($kids, 'amount')),
            'compare_amount' => $node['own_compare'] + array_sum(array_column($kids, 'compare_amount')),
            'children' => $kids,
        ];
    }

    private function prune(array $lines): array
    {
        $kept = [];
        foreach ($lines as $line) {
            $line['children'] = $this->prune($line['children']);

            if ($line['is_computed']
                || $line['children'] !== []
                || round($line['amount'], 2) != 0
                || round($line['compare_amount'], 2) != 0) {
                $kept[] = $line;
            }
        }

        return $kept;
    }

    private function totals(array $sections, string $field): array
    {
        $bySection = array_column($sections, $field, 'key');
        $assets = round($bySection['assets'], 2);
        $liabilities = round($bySection['liabilities'], 2);
        $equity = round($bySection['equity'], 2);
        $difference = round($assets - ($liabilities + $equity), 2);

        return [
            'total_assets' => $assets,
            'total_liabilities' => $liabilities,
            'total_equity' => $equity,
            'total_liabilities_and_equity' => round($liabilities + $equity, 2),
            'difference' => $difference,
            'is_balanced' => abs($difference) < 0.005,
        ];
    }
}
