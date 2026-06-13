<?php

namespace App\Central\Services;

use App\Central\Models\License;
use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

class LicenseService extends LicenseUpdateOrCreateService
{
    private const DEFAULT_STATUSES = ['active', 'suspended', 'expired', 'trial', 'grace'];

    protected array $licenseDbFields = [
        'ls.id AS id',
        'ts.name AS tenant_name',
        'ts.subdomain AS tenant_code',
        'ls.starts_at AS starts',
        'ls.expires_at AS expires',
        'ls.grace_ends_at AS grace_ends',
        'ls.created_at AS created_at',
    ];

    public function licensesListCollection(): mixed
    {
        return $this->TryCatch(function (): LengthAwarePaginator {
            $request = request();
            $statuses = $this->requestedStatuses($request->input('status', 'all'));

            $query = $this->licenseListQuery()
                ->select([
                    ...$this->licenseDbFields,
                    $this->derivedStatusSelect(),
                    $this->licenseStateSelect(),
                    $this->statusLabelSelect(),
                    $this->daysLeftSelect(),
                    $this->daysLeftTextSelect(),
                    DB::raw('IFNULL(pl.name, ls.plan) AS plan'),
                    'pl.slug AS plan_slug',
                ]);

            if ($request->filled('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $request->input('search_keyword'), $this->licenseDbFields);
            }

            return $query
                ->where(fn (Builder $query) => $this->applyDerivedStatusFilter($query, $statuses))
                ->orderByDesc('ls.created_at')
                ->paginate($this->perpage());
        });
    }

    public function licenseStats(): array
    {
        $today = now()->toDateString();

        $active = (int) $this->masterTable('licenses')
            ->where('status', 'active')
            ->whereDate('expires_at', '>=', $today)
            ->count();

        $expired = (int) $this->masterTable('licenses')
            ->where(function (Builder $query) use ($today): void {
                $query->where('status', 'expired')
                    ->orWhere(function (Builder $query) use ($today): void {
                        $query->whereIn('status', ['active', 'trial'])
                            ->whereDate('expires_at', '<', $today);
                    });
            })
            ->count();

        $trial = (int) $this->masterTable('licenses')
            ->where('status', 'trial')
            ->whereDate('expires_at', '>=', $today)
            ->count();

        $suspended = (int) $this->masterTable('licenses')->where('status', 'suspended')->count();
        $grace = (int) $this->masterTable('licenses')->where('status', 'grace')->count();
        $total = (int) $this->masterTable('licenses')->count();

        $expiringSoon = (int) $this->masterTable('licenses')
            ->whereIn('status', ['active', 'trial'])
            ->whereBetween('expires_at', [$today, now()->addDays(30)->toDateString()])
            ->count();

        $issuedThisMonth = (int) $this->masterTable('licenses')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        return [
            'total' => $total,
            'active' => $active,
            'expired' => $expired,
            'trial' => $trial,
            'suspended' => $suspended,
            'grace' => $grace,
            'expiring_soon' => $expiringSoon,
            'issued_this_month' => $issuedThisMonth,
        ];
    }

    public function licensesEditDetails(): mixed
    {
        return $this->TryCatch(function (): ?stdClass {
            return $this->licenseListQuery()
                ->select([
                    'ls.id as id',
                    'ts.id as tenant_id',
                    'pl.id as plan_id',
                    'ls.starts_at AS starts',
                    'ls.expires_at AS expires',
                    'ls.status AS status',
                ])
                ->where('ls.id', request()->input('id'))
                ->first();
        });
    }

    public function licensesDetailsCollection(): mixed
    {
        return $this->TryCatch(function (): ?stdClass {
            $license = $this->licenseListQuery()
                ->select([
                    ...$this->licenseDbFields,
                    'pl.max_users As mxusrs',
                    'pl.max_members as mx_mbrs',
                    'pl.billing_cycle as billing_type',
                    'pl.features as features',
                    'pl.slug as plan_slug',
                    'pl.price as cost',
                    DB::raw('IFNULL(pl.name, ls.plan) AS plan_name'),
                ])
                ->where('ls.id', request()->input('id'))
                ->first();

            if (! $license) {
                return null;
            }

            $license->features = $this->decodeFeatures($license->features);

            return $license;
        });
    }

    public function createLicense(): mixed
    {
        $data = request()->validate([
            'tenant_id' => 'required|string|exists:master.tenants,id',
            'plan' => [
                'required',
                'string',
                function (string $attribute, string $value, callable $fail): void {
                    if (! $this->planExists($value)) {
                        $fail('The selected license plan is invalid.');
                    }
                },
            ],
            'date' => 'required|array|min:2',
            'date.0' => 'required|date',
            'date.1' => 'required|date|after_or_equal:date.0',
            'status' => 'required|string|in:active,inactive,suspended,trial,expired,grace',
        ]);

        return $this->TryCatch(function () use ($data): mixed {
            DB::connection('master')->transaction(function () use ($data): void {
                License::create([
                    'tenant_id' => $data['tenant_id'],
                    'plan' => $this->resolvePlanId($data['plan']),
                    'starts_at' => Carbon::parse($data['date'][0])->toDateString(),
                    'expires_at' => Carbon::parse($data['date'][1])->toDateString(),
                    'status' => $data['status'],
                ]);
            });

            return $this->licensesListCollection();
        });
    }

    public function licensesDelete(): mixed
    {
        $data = request()->validate([
            'id' => 'required|string|exists:master.licenses,id',
        ]);

        return $this->TryCatch(function () use ($data): mixed {
            License::query()->whereKey($data['id'])->delete();

            return $this->licensesListCollection();
        });
    }

    public function assignLicense(Tenant $tenant, string $planIdentifier, int $durationDays = 365): License
    {
        $this->assertPositiveDuration($durationDays);

        return DB::connection('master')->transaction(function () use ($tenant, $planIdentifier, $durationDays): License {
            return $this->createActiveLicense($tenant, $planIdentifier, $durationDays);
        });
    }

    /**
     * Suspend the tenant's license.
     */
    public function suspend(Tenant $tenant): void
    {
        License::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trial', 'grace'])
            ->update(['status' => 'suspended']);
    }

    /**
     * Renew/Activate the tenant's license with a new plan and duration.
     */
    public function renew(Tenant $tenant, string $planIdentifier, int $days): void
    {
        $this->assertPositiveDuration($days);

        DB::connection('master')->transaction(function () use ($tenant, $planIdentifier, $days): void {
            License::where('tenant_id', $tenant->id)
                ->whereIn('status', ['active', 'trial', 'grace', 'suspended'])
                ->update(['status' => 'expired']);

            $this->createActiveLicense($tenant, $planIdentifier, $days);
        });
    }

    /**
     * Extend the grace period without changing the contractual expiry date.
     */
    public function extendGrace(Tenant $tenant, int $days): void
    {
        $this->assertPositiveDuration($days);

        $license = License::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'grace'])
            ->latest('expires_at')
            ->firstOrFail();

        $graceStartsAt = $license->grace_ends_at && $license->grace_ends_at->isFuture()
            ? $license->grace_ends_at
            : $license->expires_at;

        $license->update([
            'grace_ends_at' => $graceStartsAt->copy()->addDays($days)->toDateString(),
            'status' => $license->expires_at->isPast() ? 'grace' : $license->status,
        ]);
    }

    /**
     * Calculate revenue metrics for the central dashboard.
     */
    public function getRevenueMetrics(): array
    {
        $activeLicenses = DB::connection('master')
            ->table('licenses')
            ->join('plans', function ($join): void {
                $join->on('plans.id', '=', 'licenses.plan')
                    ->orOn('plans.slug', '=', 'licenses.plan');
            })
            ->where('licenses.status', 'active')
            ->whereDate('licenses.expires_at', '>=', now()->toDateString())
            ->get(['plans.price', 'plans.billing_cycle']);

        $mrr = $activeLicenses->sum(fn (stdClass $license): float => $this->monthlyPlanValue($license));

        return [
            'monthly_recurring_revenue' => (float) $mrr,
            'annual_recurring_revenue' => (float) ($mrr * 12),
        ];
    }

    /**
     * Get the count of licenses expiring within a given range.
     */
    public function getExpiringSoonCount(int $days = 3): int
    {
        return License::where('status', 'active')
            ->whereBetween('expires_at', [now(), now()->addDays($days)])
            ->count();
    }

    private function licenseListQuery(): Builder
    {
        return DB::connection('master')
            ->table('licenses as ls')
            ->join('tenants as ts', 'ls.tenant_id', '=', 'ts.id')
            ->leftJoin('plans as pl', function ($join): void {
                $join->on('pl.id', '=', 'ls.plan')
                    ->orOn('pl.slug', '=', 'ls.plan');
            });
    }

    private function masterTable(string $table): Builder
    {
        return DB::connection('master')->table($table);
    }

    private function derivedStatusSelect(): Expression
    {
        return DB::raw("
            CASE
                WHEN ls.status IN ('active', 'trial') AND DATE(ls.expires_at) < CURDATE() THEN 'expired'
                ELSE ls.status
            END AS status
        ");
    }

    private function licenseStateSelect(): Expression
    {
        return DB::raw("
            CASE
                WHEN ls.status = 'expired'
                    OR (ls.status IN ('active', 'trial') AND DATE(ls.expires_at) < CURDATE())
                    THEN 'expired'
                WHEN ls.status IN ('active', 'trial')
                    AND DATE(ls.expires_at) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    THEN 'expiring_soon'
                ELSE ls.status
            END AS status_state
        ");
    }

    private function statusLabelSelect(): Expression
    {
        return DB::raw("
            CASE
                WHEN ls.status = 'expired'
                    OR (ls.status IN ('active', 'trial') AND DATE(ls.expires_at) < CURDATE())
                    THEN 'Expired'
                WHEN ls.status IN ('active', 'trial')
                    AND DATE(ls.expires_at) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    THEN 'Expiring soon'
                WHEN ls.status = 'active' THEN 'Active'
                WHEN ls.status = 'trial' THEN 'Trial'
                WHEN ls.status = 'suspended' THEN 'Suspended'
                WHEN ls.status = 'grace' THEN 'In grace'
                ELSE ls.status
            END AS status_label
        ");
    }

    private function daysLeftSelect(): Expression
    {
        return DB::raw('DATEDIFF(DATE(ls.expires_at), CURDATE()) AS days_left');
    }

    private function daysLeftTextSelect(): Expression
    {
        return DB::raw("
            CASE
                WHEN ls.expires_at IS NULL THEN '—'
                WHEN DATEDIFF(DATE(ls.expires_at), CURDATE()) < 0
                    THEN CONCAT(
                        'Expired ',
                        ABS(DATEDIFF(DATE(ls.expires_at), CURDATE())),
                        ' ',
                        IF(ABS(DATEDIFF(DATE(ls.expires_at), CURDATE())) = 1, 'day', 'days'),
                        ' ago'
                    )
                WHEN DATEDIFF(DATE(ls.expires_at), CURDATE()) = 0 THEN 'Expires today'
                ELSE CONCAT(
                    DATEDIFF(DATE(ls.expires_at), CURDATE()),
                    ' ',
                    IF(DATEDIFF(DATE(ls.expires_at), CURDATE()) = 1, 'day', 'days'),
                    ' left'
                )
            END AS days_left_text
        ");
    }

    private function applyDerivedStatusFilter(Builder $query, array $statuses): void
    {
        $includeExpired = in_array('expired', $statuses, true);
        $storedStatuses = array_values(array_diff($statuses, ['expired']));

        $query->where(function (Builder $query) use ($storedStatuses, $includeExpired): void {
            if ($storedStatuses !== []) {
                $query->where(function (Builder $query) use ($storedStatuses): void {
                    $query->whereIn('ls.status', $storedStatuses)
                        ->where(function (Builder $query) use ($storedStatuses): void {
                            $query->whereNotIn('ls.status', ['active', 'trial'])
                                ->orWhereDate('ls.expires_at', '>=', now()->toDateString());
                        });
                });
            }

            if ($includeExpired) {
                $method = $storedStatuses === [] ? 'where' : 'orWhere';

                $query->{$method}(function (Builder $query): void {
                    $query->where('ls.status', 'expired')
                        ->orWhere(function (Builder $query): void {
                            $query->whereIn('ls.status', ['active', 'trial'])
                                ->whereDate('ls.expires_at', '<', now()->toDateString());
                        });
                });
            }
        });
    }

    private function requestedStatuses(?string $status): array
    {
        if (! $status || $status === 'all') {
            return self::DEFAULT_STATUSES;
        }

        return in_array($status, self::DEFAULT_STATUSES, true)
            ? [$status]
            : self::DEFAULT_STATUSES;
    }

    private function resolvePlanId(string $planIdentifier): string
    {
        $planId = Plan::query()
            ->where('id', $planIdentifier)
            ->orWhere('slug', $planIdentifier)
            ->value('id');

        if (! $planId) {
            throw new InvalidArgumentException("Unknown license plan [{$planIdentifier}].");
        }

        return (string) $planId;
    }

    private function planExists(string $planIdentifier): bool
    {
        return Plan::query()
            ->where('id', $planIdentifier)
            ->orWhere('slug', $planIdentifier)
            ->exists();
    }

    private function createActiveLicense(Tenant $tenant, string $planIdentifier, int $durationDays): License
    {
        $startsAt = now();

        return License::create([
            'tenant_id' => $tenant->id,
            'plan' => $this->resolvePlanId($planIdentifier),
            'starts_at' => $startsAt->toDateString(),
            'expires_at' => $startsAt->copy()->addDays($durationDays)->toDateString(),
            'status' => 'active',
        ]);
    }

    private function assertPositiveDuration(int $days): void
    {
        if ($days < 1) {
            throw new InvalidArgumentException('License duration must be at least one day.');
        }
    }

    private function decodeFeatures(mixed $features): array
    {
        if ($features instanceof Collection) {
            return $features->toArray();
        }

        if (is_array($features)) {
            return $features;
        }

        if (! is_string($features) || trim($features) === '') {
            return [];
        }

        $decoded = json_decode($features, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function monthlyPlanValue(stdClass $license): float
    {
        $price = (float) $license->price;
        $billingCycle = strtolower((string) $license->billing_cycle);

        return match ($billingCycle) {
            'yearly', 'annual', 'annually' => $price / 12,
            default => $price,
        };
    }
}
