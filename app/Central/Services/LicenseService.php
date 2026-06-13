<?php

namespace App\Central\Services;

use App\Central\Models\License;
use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class LicenseService extends LicenseUpdateOrCreateService
{
    private const DEFAULT_STATUSES = ['active', 'suspended', 'expired', 'trial', 'grace'];

    protected array $LicenseDbFields = [
        'ls.id AS id',
        'ts.name AS tenant_name',
        'ts.subdomain AS tenant_code',
        'ls.starts_at AS starts',
        'ls.expires_at AS expires',
        'ls.grace_ends_at AS grace_ends',
        // "ls.max_members AS members",
        // "ls.max_users AS users",
        'ls.created_at AS created_at',
    ];

    public function licensesListCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $statuses = $this->requestedStatuses($req['status'] ?? 'all');
            $query = DB::connection('master')->table('licenses as ls')
                ->Join('tenants as ts', 'ls.tenant_id', '=', 'ts.id')
                ->leftJoin('plans as pl', 'pl.id', '=', 'ls.plan')
                ->select([
                    ...$this->LicenseDbFields,
                    $this->derivedStatusSelect(),
                    $this->licenseStateSelect(),
                    $this->statusLabelSelect(),
                    $this->daysLeftSelect(),
                    $this->daysLeftTextSelect(),
                    DB::raw('IFNULL(pl.name, ls.plan) AS plan'),
                    'pl.slug AS plan_slug',
                    'pl.price AS cost',
                    'pl.billing_cycle AS billing_type',
                ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->LicenseDbFields);
            }

            return $query
                // ->whereNull("deleted_at")
                ->where(fn (Builder $query) => $this->applyDerivedStatusFilter($query, $statuses))
                ->orderBy('ls.created_at', 'DESC')->paginate($this->perpage());
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

    public function licensesEditDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::connection('master')->table('licenses as ls')
                ->Join('tenants as ts', 'ls.tenant_id', '=', 'ts.id')
                ->leftJoin('plans as pl', 'pl.id', '=', 'ls.plan')
                ->select([
                    // ...$this->LicenseDbFields,
                    'ls.id as id',
                    'ts.id as tenant_id',
                    'pl.id as plan_id',
                    'ls.starts_at AS starts',
                    'ls.expires_at AS expires',
                    'ls.status AS status',
                ]);

            $data = $query->whereRaw('ls.id=?', [$req->id])
                ->first();

            return $data;
        });
    }

    public function licensesDetailsCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::connection('master')->table('licenses as ls')
                ->Join('tenants as ts', 'ls.tenant_id', '=', 'ts.id')
                ->leftJoin('plans as pl', 'pl.id', '=', 'ls.plan')
                ->select([
                    ...$this->LicenseDbFields,
                    'pl.max_users As mxusrs',
                    'pl.max_members as mx_mbrs',
                    'pl.billing_cycle as billing_type',
                    'pl.features as features',
                    'pl.slug as plan_slug',
                    'pl.price as cost',
                    DB::raw('IFNULL(pl.name, ls.plan) AS plan_name'),
                ]);

            $data = $query->whereRaw('ls.id=?', [$req->id])
                ->first();
            $data->features = $this->isJSONToArray(json_decode($data->features, true)); // i have done that i expect un perfect data  some data

            return $data;
        });
    }

    public function assignLicense(Tenant $tenant, string $planSlug, int $durationMonthsMonth = 12): License
    {
        return License::create([
            'tenant_id' => $tenant->id,
            'plan' => $this->resolvePlanId($planSlug),
            'starts_at' => Carbon::now()->toDateString(),
            'expires_at' => Carbon::now()->addDays($durationMonthsMonth)->toDateString(),
            'status' => 'active',
        ]);
    }

    /**
     * Suspend the tenant's license.
     */
    public function suspend(Tenant $tenant): void
    {
        License::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->update(['status' => 'suspended']);
    }

    /**
     * Renew/Activate the tenant's license with a new plan and duration.
     */
    public function renew(Tenant $tenant, string $planSlug, int $days): void
    {
        License::where('tenant_id', $tenant->id)->update(['status' => 'expired']); // Deactivate current

        License::create([
            'tenant_id' => $tenant->id,
            'plan' => $this->resolvePlanId($planSlug),
            'starts_at' => Carbon::now()->toDateString(),
            'expires_at' => Carbon::now()->addDays($days)->toDateString(),
            'status' => 'active',
        ]);
    }

    public function licenseRenewalPreview(): mixed
    {
        return $this->TryCatch(function (): array {
            $license = $this->getLicenseForRenewal((string) request('id'));
            $plan = $this->resolveRenewalPlan(request('plan_id'), $license->plan_id);
            $billingCycle = request('billing_cycle', $plan->billing_cycle ?? 'annual');
            $dates = $this->renewalDates($license->expires, $billingCycle, $plan->days ?? null);
            $amount = (float) ($plan->price ?? 0);

            return $this->buildRenewalPayload($license, $plan, $billingCycle, $dates, $amount);
        });
    }

    public function licenseRenew(): mixed
    {
        return $this->TryCatch(function (): array {
            $data = request()->validate([
                'id' => 'required|string|exists:master.licenses,id',
                'plan_id' => 'nullable',
                'billing_cycle' => 'nullable|string|in:weekly,monthly,quarterly,annual,yearly',
                'payment_method' => 'required|string|in:mobile_money,card,bank',
                'provider' => 'nullable|string|max:100',
                'phone_number' => 'nullable|string|max:40',
                'account_name' => 'nullable|string|max:150',
                'save_payment_method' => 'nullable|boolean',
            ]);

            return DB::connection('master')->transaction(function () use ($data): array {
                $license = $this->getLicenseForRenewal($data['id']);
                $plan = $this->resolveRenewalPlan($data['plan_id'] ?? null, $license->plan_id);
                $billingCycle = $data['billing_cycle'] ?? $plan->billing_cycle ?? 'annual';
                $dates = $this->renewalDates($license->expires, $billingCycle, $plan->days ?? null);
                $amount = (float) ($plan->price ?? 0);
                $now = now();

                $invoiceId = (string) DB::connection('master')->table('license_invoices')->insertGetId([
                    'license_id' => $license->id,
                    'tenant_id' => $license->tenant_id,
                    'plan_id' => $plan->id,
                    'invoice_number' => 'INV-'.$now->format('YmdHis').'-'.substr((string) $license->id, 0, 6),
                    'currency' => 'UGX',
                    'subtotal' => $amount,
                    'service_fee' => 0,
                    'total' => $amount,
                    'status' => 'paid',
                    'due_at' => $now->toDateString(),
                    'paid_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::connection('master')->table('license_payments')->insert([
                    'license_invoice_id' => $invoiceId,
                    'tenant_id' => $license->tenant_id,
                    'amount' => $amount,
                    'currency' => 'UGX',
                    'payment_method' => $data['payment_method'],
                    'provider' => $data['provider'] ?? null,
                    'phone_number' => $data['phone_number'] ?? null,
                    'account_name' => $data['account_name'] ?? null,
                    'save_payment_method' => (bool) ($data['save_payment_method'] ?? false),
                    'status' => 'paid',
                    'paid_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                License::query()
                    ->where('tenant_id', $license->tenant_id)
                    ->whereIn('status', ['active', 'trial', 'grace', 'suspended'])
                    ->update(['status' => 'expired']);

                $newLicense = License::create([
                    'tenant_id' => $license->tenant_id,
                    'plan' => $plan->id,
                    'starts_at' => $dates['start']->toDateString(),
                    'expires_at' => $dates['end']->toDateString(),
                    'status' => 'active',
                ]);

                return [
                    ...$this->buildRenewalPayload($license, $plan, $billingCycle, $dates, $amount),
                    'invoice_id' => $invoiceId,
                    'new_license_id' => $newLicense->id,
                    'payment_status' => 'paid',
                    'message' => 'License renewed successfully.',
                ];
            });
        });
    }

    public function licenseInvoices(): mixed
    {
        return $this->TryCatch(function () {
            $license = $this->getLicenseForRenewal((string) request('id'));

            return DB::connection('master')->table('license_invoices')
                ->where('tenant_id', $license->tenant_id)
                ->orderByDesc('created_at')
                ->get();
        });
    }

    /**
     * Extend the grace period by pushing the expiry date.
     */
    public function extendGrace(Tenant $tenant, int $days): void
    {
        $license = License::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->firstOrFail();

        $license->update([
            'expires_at' => $license->expires_at->addDays($days),
        ]);
    }

    /**
     * Calculate revenue metrics for the central dashboard.
     */
    public function getRevenueMetrics(): array
    {
        $mrr = DB::connection('master')
            ->table('licenses')
            ->join('plans', 'licenses.plan', '=', 'plans.slug')
            ->where('licenses.status', 'active')
            ->sum('plans.price');

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
            ->whereBetween('expires_at', [Carbon::now(), Carbon::now()->addDays($days)])
            ->count();
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
                    THEN CONCAT('Expired ', ABS(DATEDIFF(DATE(ls.expires_at), CURDATE())), ' ', IF(ABS(DATEDIFF(DATE(ls.expires_at), CURDATE())) = 1, 'day', 'days'), ' ago')
                WHEN DATEDIFF(DATE(ls.expires_at), CURDATE()) = 0 THEN 'Expires today'
                ELSE CONCAT(DATEDIFF(DATE(ls.expires_at), CURDATE()), ' ', IF(DATEDIFF(DATE(ls.expires_at), CURDATE()) = 1, 'day', 'days'), ' left')
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
                        ->where(function (Builder $query): void {
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

    private function getLicenseForRenewal(string $id): object
    {
        return DB::connection('master')->table('licenses as ls')
            ->join('tenants as ts', 'ls.tenant_id', '=', 'ts.id')
            ->leftJoin('plans as pl', 'pl.id', '=', 'ls.plan')
            ->where('ls.id', $id)
            ->first([
                'ls.id',
                'ls.tenant_id',
                'ts.name AS tenant_name',
                'ts.subdomain AS tenant_code',
                'ls.plan AS plan_id',
                'ls.starts_at AS starts',
                'ls.expires_at AS expires',
                'ls.status',
                DB::raw('IFNULL(pl.name, ls.plan) AS plan_name'),
                'pl.slug AS plan_slug',
                'pl.billing_cycle',
                'pl.price',
                'pl.days',
            ]) ?? abort(404, 'License not found.');
    }

    private function resolveRenewalPlan(mixed $planId, mixed $fallbackPlanId): object
    {
        $identifier = $planId ?: $fallbackPlanId;

        return DB::connection('master')->table('plans')
            ->where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first() ?? abort(422, 'Selected renewal plan is invalid.');
    }

    private function resolvePlanId(string $planIdentifier): string
    {
        $planId = DB::connection('master')->table('plans')
            ->where('id', $planIdentifier)
            ->orWhere('slug', $planIdentifier)
            ->value('id');

        return (string) ($planId ?: $planIdentifier);
    }

    private function renewalDates(?string $currentExpiry, string $billingCycle, ?int $planDays): array
    {
        $today = now()->startOfDay();
        $expiry = $currentExpiry ? Carbon::parse($currentExpiry)->startOfDay() : null;
        $start = $expiry && $expiry->greaterThanOrEqualTo($today)
            ? $expiry->copy()->addDay()
            : $today->copy();

        $end = match ($billingCycle) {
            'weekly' => $start->copy()->addWeek()->subDay(),
            'monthly' => $start->copy()->addMonth()->subDay(),
            'quarterly' => $start->copy()->addMonths(3)->subDay(),
            'annual', 'yearly' => $start->copy()->addYear()->subDay(),
            default => $planDays ? $start->copy()->addDays($planDays)->subDay() : $start->copy()->addYear()->subDay(),
        };

        return ['start' => $start, 'end' => $end];
    }

    private function buildRenewalPayload(object $license, object $plan, string $billingCycle, array $dates, float $amount): array
    {
        return [
            'license_id' => $license->id,
            'tenant_id' => $license->tenant_id,
            'tenant' => $license->tenant_name,
            'tenant_code' => $license->tenant_code,
            'current_plan' => $license->plan_name,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'billing_cycle' => $billingCycle,
            'renewal_months' => $this->cycleMonths($billingCycle),
            'renewal_start' => $dates['start']->toDateString(),
            'renewal_end' => $dates['end']->toDateString(),
            'next_billing_date' => $dates['end']->copy()->addDay()->toDateString(),
            'subtotal' => $amount,
            'service_fee' => 0,
            'total' => $amount,
            'currency' => 'UGX',
            'status_label' => $this->licenseStatusLabel($license->status, $license->expires),
        ];
    }

    private function cycleMonths(string $billingCycle): int
    {
        return match ($billingCycle) {
            'weekly' => 0,
            'monthly' => 1,
            'quarterly' => 3,
            default => 12,
        };
    }

    private function licenseStatusLabel(string $status, ?string $expires): string
    {
        if (in_array($status, ['active', 'trial'], true) && $expires && Carbon::parse($expires)->isPast()) {
            return 'Expired';
        }

        return [
            'active' => 'Active',
            'trial' => 'Trial',
            'expired' => 'Expired',
            'suspended' => 'Suspended',
            'grace' => 'In grace',
        ][$status] ?? $status;
    }
}
