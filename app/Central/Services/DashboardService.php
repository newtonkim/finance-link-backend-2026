<?php

namespace App\Central\Services;

use App\Http\Globals\GlobalHelpers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Metrics behind the central (platform owner) dashboard.
 *
 * Every figure here is derived from the central database; nothing is illustrative.
 * Two rules the previous implementation broke are worth stating plainly:
 *
 *  - Revenue counts only licences that have not expired. A lapsed tenant is not
 *    recurring revenue, however recently it lapsed.
 *  - Billing cycles are normalised to a monthly figure before being summed, so a
 *    yearly plan contributes a twelfth of its price to MRR rather than its full
 *    price.
 */
class DashboardService extends GlobalHelpers
{
    /** How long the cross-tenant reach figures stay warm. They are the expensive part. */
    private const REACH_TTL_SECONDS = 300;

    /** Multipliers that convert one billing cycle's price into a monthly figure. */
    private const CYCLE_TO_MONTHLY = [
        'weekly' => 52 / 12,
        'monthly' => 1.0,
        'quarterly' => 1 / 3,
        'yearly' => 1 / 12,
        'annual' => 1 / 12,
    ];

    public function DashbordsAnalysis(): array
    {
        return [
            'currency' => $this->currency(),
            'generated_at' => now()->toIso8601String(),
            'tenants' => $this->tenantMetrics(),
            'licenses' => $this->licenseMetrics(),
            'revenue' => $this->revenueMetrics(),
            'growth' => $this->growthSeries(),
            'plans' => $this->planDistribution(),
            'attention' => $this->attentionFeed(),
            'recent_tenants' => $this->recentTenants(),
            'reach' => $this->platformReach(),
        ];
    }

    // ── Headline counts ───────────────────────────────────────────────────────

    private function tenantMetrics(): array
    {
        $row = DB::connection('master')->table('tenants')
            ->whereNull('deleted_at')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(status = 'active') AS active")
            ->selectRaw("SUM(status = 'suspended') AS suspended")
            ->selectRaw('SUM(created_at >= ?) AS new_this_month', [now()->startOfMonth()])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'suspended' => (int) ($row->suspended ?? 0),
            'new_this_month' => (int) ($row->new_this_month ?? 0),
        ];
    }

    /**
     * Licence health keys off expires_at rather than the status column, which drifts:
     * rows routinely read status='active' long after their expiry date has passed.
     */
    private function licenseMetrics(): array
    {
        $now = now();

        $row = DB::connection('master')->table('licenses')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(expires_at >= ?) AS active', [$now])
            ->selectRaw('SUM(expires_at < ?) AS expired', [$now])
            ->selectRaw('SUM(expires_at BETWEEN ? AND ?) AS expiring_3_days', [$now, $now->copy()->addDays(3)])
            ->selectRaw('SUM(expires_at BETWEEN ? AND ?) AS expiring_7_days', [$now, $now->copy()->addDays(7)])
            ->selectRaw('SUM(expires_at BETWEEN ? AND ?) AS expiring_30_days', [$now, $now->copy()->addDays(30)])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            // Retained because the summary endpoint has always published a 3-day window.
            'expiring_3_days' => (int) ($row->expiring_3_days ?? 0),
            'expiring_7_days' => (int) ($row->expiring_7_days ?? 0),
            'expiring_30_days' => (int) ($row->expiring_30_days ?? 0),
        ];
    }

    // ── Revenue ───────────────────────────────────────────────────────────────

    private function revenueMetrics(): array
    {
        $rows = DB::connection('master')->table('licenses as l')
            ->join('plans as p', 'l.plan_id', '=', 'p.id')
            ->where('l.expires_at', '>=', now())
            ->whereNull('p.deleted_at')
            ->selectRaw('p.billing_cycle AS cycle, SUM(p.price) AS gross, COUNT(*) AS licences')
            ->groupBy('p.billing_cycle')
            ->get();

        $mrr = 0.0;
        $byCycle = [];

        foreach ($rows as $row) {
            $cycle = strtolower((string) $row->cycle);
            $gross = (float) $row->gross;
            $mrr += $gross * (self::CYCLE_TO_MONTHLY[$cycle] ?? 1.0);

            $byCycle[] = [
                'cycle' => $cycle,
                'gross' => round($gross, 2),
                'licences' => (int) $row->licences,
            ];
        }

        $mrr = round($mrr, 2);

        return [
            'mrr' => $mrr,
            'arr' => round($mrr * 12, 2),
            'by_cycle' => $byCycle,
        ];
    }

    // ── Growth ────────────────────────────────────────────────────────────────

    /**
     * Twelve months of tenant sign-ups, with a running total. The cumulative line is
     * the readable one on a young platform: monthly sign-ups are mostly zeroes.
     */
    private function growthSeries(): array
    {
        $start = now()->copy()->startOfMonth()->subMonths(11);

        $counts = DB::connection('master')->table('tenants')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $start)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c")
            ->groupBy('ym')
            ->pluck('c', 'ym');

        // Everything created before the window still counts toward the running total.
        $running = (int) DB::connection('master')->table('tenants')
            ->whereNull('deleted_at')
            ->where('created_at', '<', $start)
            ->count();

        $series = [];

        for ($i = 0; $i < 12; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $new = (int) ($counts[$key] ?? 0);
            $running += $new;

            $series[] = [
                'month' => $key,
                'label' => $month->format('M'),
                'new' => $new,
                'cumulative' => $running,
            ];
        }

        return $series;
    }

    // ── Plans ─────────────────────────────────────────────────────────────────

    /**
     * Plans with the number of live licences on each. Reported as a list rather than
     * a share-of-total chart: with a handful of plans a table is the honest form.
     */
    private function planDistribution(): array
    {
        return DB::connection('master')->table('plans as p')
            ->leftJoin('licenses as l', function ($join) {
                $join->on('l.plan_id', '=', 'p.id')->where('l.expires_at', '>=', now());
            })
            ->whereNull('p.deleted_at')
            ->groupBy('p.id', 'p.name', 'p.price', 'p.billing_cycle')
            ->orderByDesc('p.price')
            ->selectRaw('p.id, p.name, p.price, p.billing_cycle, COUNT(l.id) AS active_licences')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'price' => round((float) $row->price, 2),
                'billing_cycle' => (string) $row->billing_cycle,
                'active_licences' => (int) $row->active_licences,
            ])
            ->all();
    }

    // ── Attention feed ────────────────────────────────────────────────────────

    /**
     * The operational to-do list: what a platform owner has to act on today. Ordered
     * most-urgent first so the surface can render it without re-sorting.
     */
    private function attentionFeed(): array
    {
        $now = now();

        $licences = DB::connection('master')->table('licenses as l')
            ->join('tenants as t', 't.id', '=', 'l.tenant_id')
            ->leftJoin('plans as p', 'p.id', '=', 'l.plan_id')
            ->whereNull('t.deleted_at')
            ->where('l.expires_at', '<=', $now->copy()->addDays(30))
            ->orderBy('l.expires_at')
            ->selectRaw('t.name, t.subdomain, l.expires_at, p.name AS plan')
            ->limit(10)
            ->get()
            ->map(function ($row) use ($now) {
                $expires = Carbon::parse($row->expires_at);
                $days = (int) $now->copy()->startOfDay()->diffInDays($expires->copy()->startOfDay(), false);

                return [
                    'type' => $days < 0 ? 'licence_expired' : 'licence_expiring',
                    'severity' => $days < 0 ? 'critical' : ($days <= 7 ? 'warning' : 'info'),
                    'tenant' => (string) $row->name,
                    'subdomain' => (string) $row->subdomain,
                    'plan' => $row->plan ? (string) $row->plan : null,
                    'expires_at' => $expires->toDateString(),
                    'days' => $days,
                ];
            });

        $suspended = DB::connection('master')->table('tenants')
            ->whereNull('deleted_at')
            ->where('status', 'suspended')
            ->orderByDesc('updated_at')
            ->select('name', 'subdomain')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'type' => 'tenant_suspended',
                'severity' => 'serious',
                'tenant' => (string) $row->name,
                'subdomain' => (string) $row->subdomain,
                'plan' => null,
                'expires_at' => null,
                'days' => null,
            ]);

        $rank = ['critical' => 0, 'serious' => 1, 'warning' => 2, 'info' => 3];

        return $licences->concat($suspended)
            ->sortBy(fn ($item) => [$rank[$item['severity']] ?? 9, $item['days'] ?? 0])
            ->values()
            ->all();
    }

    private function recentTenants(): array
    {
        return DB::connection('master')->table('tenants as t')
            ->leftJoin('licenses as l', 'l.tenant_id', '=', 't.id')
            ->leftJoin('plans as p', 'p.id', '=', 'l.plan_id')
            ->whereNull('t.deleted_at')
            ->orderByDesc('t.created_at')
            ->selectRaw('t.name, t.subdomain, t.status, t.created_at, p.name AS plan, l.expires_at')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'subdomain' => (string) $row->subdomain,
                'status' => (string) $row->status,
                'created_at' => Carbon::parse($row->created_at)->toDateString(),
                'plan' => $row->plan ? (string) $row->plan : null,
                'licence_active' => $row->expires_at ? Carbon::parse($row->expires_at)->isFuture() : false,
            ])
            ->all();
    }

    // ── Cross-tenant reach ────────────────────────────────────────────────────

    /**
     * Members and balances across every tenant database — what the platform actually
     * carries, as opposed to how many tenants are signed up.
     *
     * Each tenant lives in its own schema on the same server, so this reads them with
     * qualified cross-database queries rather than switching the active connection:
     * no global connection state is mutated and a broken tenant cannot poison the
     * request. One unreachable tenant is reported, not thrown — a dashboard that
     * 500s because a single schema is mid-migration is worse than one that says so.
     */
    private function platformReach(): array
    {
        return Cache::remember('central:dashboard:reach', self::REACH_TTL_SECONDS, function () {
            $tenants = DB::connection('master')->table('tenants')
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->pluck('database_name');

            $members = 0;
            $savings = 0.0;
            $loans = 0.0;
            $counted = 0;
            $unreachable = 0;

            foreach ($tenants as $database) {
                // Schema names come from our own tenants table, but they are still
                // interpolated into SQL, so anything unexpected is skipped outright.
                if (! is_string($database) || ! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
                    $unreachable++;

                    continue;
                }

                try {
                    $row = DB::connection('master')->selectOne("
                        SELECT
                            (SELECT COUNT(*) FROM `{$database}`.`members` WHERE deleted_at IS NULL) AS members,
                            (SELECT COALESCE(SUM(balance), 0) FROM `{$database}`.`savings_accounts` WHERE deleted_at IS NULL) AS savings,
                            (SELECT COALESCE(SUM(outstanding_balance), 0) FROM `{$database}`.`loans` WHERE deleted_at IS NULL) AS loans
                    ");

                    $members += (int) ($row->members ?? 0);
                    $savings += (float) ($row->savings ?? 0);
                    $loans += (float) ($row->loans ?? 0);
                    $counted++;
                } catch (\Throwable) {
                    $unreachable++;
                }
            }

            return [
                'members' => $members,
                'savings_balance' => round($savings, 2),
                'loans_outstanding' => round($loans, 2),
                'tenants_counted' => $counted,
                'tenants_unreachable' => $unreachable,
            ];
        });
    }

    private function currency(): string
    {
        try {
            $row = DB::connection('master')->table('central_currency_settings')->first();

            // No settings row yet on a fresh platform, so fall through to the default.
            return $row && $row->default_currency ? (string) $row->default_currency : 'UGX';
        } catch (\Throwable) {
            return 'UGX';
        }
    }
}
