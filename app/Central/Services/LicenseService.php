<?php

namespace App\Central\Services;

use App\Central\Models\License;
use App\Central\Models\LicenseInvoice;
use App\Central\Models\LicensePayment;
use App\Central\Models\LicensePaymentAttempt;
use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use App\Http\Globals\GlobalHelpers;
use App\Mail\LicenseInvoiceMail;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use stdClass;

class LicenseService extends GlobalHelpers
{
    private const DEFAULT_STATUSES = ['active', 'suspended', 'expired', 'trial', 'grace'];

    /** Mobile money in the Uganda market (MTN/Airtel) settles in UGX only. */
    private const MOBILE_MONEY_CURRENCY = 'UGX';

    /** Plan prices are authored and stored in USD (see the "Cost (USD)" field). */
    private const PLAN_PRICING_CURRENCY = 'USD';

    /**
     * Reference mid-market rates expressed per 1 USD. Used to snapshot an FX
     * rate when the charge currency differs from the settlement (base)
     * currency. Overridable via config('billing.currency_rates').
     */
    private const REFERENCE_RATES_PER_USD = [
        'USD' => 1.0, 'EUR' => 0.92, 'GBP' => 0.79, 'JPY' => 157.18,
        'CHF' => 0.8991, 'CAD' => 1.3614, 'AUD' => 1.5274, 'CNY' => 7.2584,
        'INR' => 83.92, 'KES' => 129.50, 'UGX' => 3735.0, 'TZS' => 2605.0,
        'RWF' => 1304.0, 'ZAR' => 18.12, 'NGN' => 1487.0, 'GHS' => 15.28,
        'AED' => 3.6725, 'SAR' => 3.75,
    ];

    /** Currencies that have no minor units (whole-number amounts only). */
    private const ZERO_DECIMAL_CURRENCIES = ['UGX', 'JPY', 'RWF', 'TZS'];

    /** A license is flagged "expiring soon" only within this many days of expiry. */
    private const EXPIRING_SOON_DAYS = 7;

    protected array $licenseDbFields = [
        'ls.id AS id',
        'ts.name AS tenant_name',
        'ts.subdomain AS tenant_code',
        'ls.starts_at AS starts',
        'ls.expires_at AS expires',
        'ls.grace_ends_at AS grace_ends',
        'ls.created_at AS created_at',
    ];

    public function licensesListCollection(mixed $request = null): mixed
    {
        return $this->TryCatch(function () use ($request): LengthAwarePaginator {
            $request = $request ?: request();
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
                    'ls.plan_id',
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

    public function licensesEditDetails(string $id): mixed
    {
        return $this->TryCatch(function () use ($id): ?stdClass {
            return $this->licenseListQuery()
                ->select([
                    'ls.id as id',
                    'ts.id as tenant_id',
                    'pl.id as plan_id',
                    'ls.starts_at AS starts',
                    'ls.expires_at AS expires',
                    'ls.status AS status',
                ])
                ->where('ls.id', $id)
                ->first();
        });
    }

    public function licensesDetailsCollection(string $id): mixed
    {
        return $this->TryCatch(function () use ($id): ?stdClass {
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
                ->where('ls.id', $id)
                ->first();

            if (! $license) {
                return null;
            }

            $license->features = $this->decodeFeatures($license->features);

            return $license;
        });
    }

    public function createLicense(array $input): mixed
    {
        $data = Validator::make($input, [
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
        ])->validate();

        return $this->TryCatch(function () use ($data): mixed {
            DB::connection('master')->transaction(function () use ($data): void {
                $planId = $this->resolvePlanId($data['plan']);

                License::create([
                    'tenant_id' => $data['tenant_id'],
                    'plan_id' => $planId,
                    'plan' => $planId,
                    'starts_at' => Carbon::parse($data['date'][0])->toDateString(),
                    'expires_at' => Carbon::parse($data['date'][1])->toDateString(),
                    'status' => $data['status'],
                ]);
            });

            return $this->licensesListCollection();
        });
    }

    public function licensesDelete(array $input): mixed
    {
        $data = Validator::make($input, [
            'id' => 'required|string|exists:master.licenses,id',
        ])->validate();

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

    public function licenseRenewalPreview(array $input): mixed
    {
        return $this->TryCatch(function () use ($input): array {
            $data = Validator::make($input, [
                'id' => 'required|string|exists:master.licenses,id',
                'plan_id' => 'nullable',
                'billing_cycle' => 'nullable|string|in:weekly,monthly,quarterly,annual,yearly',
            ])->validate();

            $license = $this->getLicenseForRenewal((string) $data['id']);
            $plan = $this->resolveRenewalPlan($data['plan_id'] ?? null, $license->plan_id);
            $billingCycle = $data['billing_cycle'] ?? $plan->billing_cycle ?? 'annual';
            $dates = $this->renewalDates($license->expires, $billingCycle, $plan->days ?? null);
            $amount = $this->planAmountInBaseCurrency($plan);

            return $this->buildRenewalPayload($license, $plan, $billingCycle, $dates, $amount);
        });
    }

    public function licenseRenew(array $input, ?string $idempotencyHeader = null): mixed
    {
        return $this->TryCatch(function () use ($input, $idempotencyHeader): array {
            $data = Validator::make($input, [
                'id' => 'required|string|exists:master.licenses,id',
                'plan_id' => 'nullable',
                'billing_cycle' => 'nullable|string|in:weekly,monthly,quarterly,annual,yearly',
                'payment_method' => 'required|string|in:mobile_money,card,bank',
                'currency' => 'nullable|string|size:3',
                'provider' => 'nullable|string|max:100',
                'phone_number' => 'nullable|string|max:40',
                'account_name' => 'nullable|string|max:150',
                'save_payment_method' => 'nullable|boolean',
                'provider_reference' => 'nullable|string|max:160',
                'idempotency_key' => 'nullable|string|max:120',
                'gateway_payload' => 'nullable|array',
            ])->validate();

            return DB::connection('master')->transaction(function () use ($data, $idempotencyHeader): array {
                $license = $this->getLicenseForRenewal($data['id']);
                $plan = $this->resolveRenewalPlan($data['plan_id'] ?? null, $license->plan_id);
                $billingCycle = $data['billing_cycle'] ?? $plan->billing_cycle ?? 'annual';
                $dates = $this->renewalDates($license->expires, $billingCycle, $plan->days ?? null);
                $amount = $this->planAmountInBaseCurrency($plan);

                // Resolve and snapshot the charge currency + FX rate. The invoice is
                // recorded in the settlement (base) currency; the payment captures the
                // currency the customer is actually charged in.
                $baseCurrency = $this->baseCurrency();
                $chargeCurrency = $this->resolveChargeCurrency(
                    $data['payment_method'],
                    $data['currency'] ?? null,
                    $baseCurrency,
                    $this->enabledCurrencies()
                );
                $fxRate = $this->exchangeRate($baseCurrency, $chargeCurrency);
                $chargeAmount = $this->convertAmount($amount, $fxRate, $chargeCurrency);

                $now = now();
                $actorId = auth('sanctum')->id() ?? auth('platform')->id();
                $idempotencyKey = $data['idempotency_key']
                    ?? $idempotencyHeader
                    ?? 'license-renewal-'.(string) Str::uuid();

                $existingInvoice = LicenseInvoice::query()
                    ->with('payments')
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingInvoice) {
                    return [
                        ...$this->buildRenewalPayload($license, $plan, $billingCycle, $dates, $amount),
                        'invoice_id' => (string) $existingInvoice->id,
                        'payment_id' => (string) optional($existingInvoice->payments->first())->id,
                        'invoice_status' => $existingInvoice->status,
                        'payment_status' => optional($existingInvoice->payments->first())->status,
                        'license_status' => $license->status,
                        'base_currency' => $existingInvoice->currency,
                        'charge_currency' => $existingInvoice->charge_currency,
                        'fx_rate' => (float) $existingInvoice->fx_rate,
                        'charge_total' => (float) $existingInvoice->charge_total,
                        'idempotency_key' => $idempotencyKey,
                        'message' => 'Existing renewal invoice returned for this idempotency key.',
                    ];
                }

                $invoice = LicenseInvoice::create([
                    'license_id' => $license->id,
                    'tenant_id' => $license->tenant_id,
                    'plan_id' => $plan->id,
                    'billing_cycle' => $billingCycle,
                    'invoice_number' => 'INV-'.$now->format('YmdHis').'-'.substr((string) $license->id, 0, 6),
                    'idempotency_key' => $idempotencyKey,
                    'currency' => $baseCurrency,
                    'charge_currency' => $chargeCurrency,
                    'fx_rate' => $fxRate,
                    'charge_total' => $chargeAmount,
                    'subtotal' => $amount,
                    'service_fee' => 0,
                    'total' => $amount,
                    'status' => LicenseInvoice::STATUS_INVOICE_PENDING,
                    'due_at' => $now->toDateString(),
                    'renewal_starts_at' => $dates['start']->toDateString(),
                    'renewal_expires_at' => $dates['end']->toDateString(),
                    'paid_at' => null,
                    'gateway_payload' => $data['gateway_payload'] ?? null,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                $payment = LicensePayment::create([
                    'license_invoice_id' => $invoice->id,
                    'tenant_id' => $license->tenant_id,
                    'amount' => $chargeAmount,
                    'currency' => $chargeCurrency,
                    'base_currency' => $baseCurrency,
                    'base_amount' => $amount,
                    'fx_rate' => $fxRate,
                    'payment_method' => $data['payment_method'],
                    'provider' => $data['provider'] ?? null,
                    'provider_reference' => $data['provider_reference'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'phone_number' => $data['phone_number'] ?? null,
                    'account_name' => $data['account_name'] ?? null,
                    'save_payment_method' => (bool) ($data['save_payment_method'] ?? false),
                    'status' => LicensePayment::STATUS_PAYMENT_PENDING,
                    'paid_at' => null,
                    'gateway_payload' => $data['gateway_payload'] ?? null,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                LicensePaymentAttempt::create([
                    'license_payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                    'idempotency_key' => $idempotencyKey,
                    'status' => LicensePayment::STATUS_PAYMENT_PENDING,
                    'request_payload' => [
                        'payment_method' => $payment->payment_method,
                        'base_amount' => $amount,
                        'base_currency' => $baseCurrency,
                        'amount' => $chargeAmount,
                        'currency' => $chargeCurrency,
                        'fx_rate' => $fxRate,
                    ],
                    'response_payload' => $data['gateway_payload'] ?? null,
                    'attempted_at' => $now,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                return [
                    ...$this->buildRenewalPayload($license, $plan, $billingCycle, $dates, $amount),
                    'invoice_id' => (string) $invoice->id,
                    'payment_id' => (string) $payment->id,
                    'invoice_status' => $invoice->status,
                    'payment_status' => $payment->status,
                    'license_status' => $license->status,
                    'base_currency' => $baseCurrency,
                    'charge_currency' => $chargeCurrency,
                    'fx_rate' => $fxRate,
                    'charge_total' => $chargeAmount,
                    'idempotency_key' => $idempotencyKey,
                    'message' => 'Renewal invoice created. License will activate after payment confirmation.',
                ];
            });
        });
    }

    public function licenseInvoices(string $licenseId): mixed
    {
        return $this->TryCatch(function () use ($licenseId) {
            $license = $this->getLicenseForRenewal($licenseId);

            return DB::connection('master')->table('license_invoices')
                ->where('tenant_id', $license->tenant_id)
                ->orderByDesc('created_at')
                ->get();
        });
    }

    /**
     * Assemble the full, formatted data set for an invoice document (PDF / email).
     */
    public function licenseInvoiceDocumentData(string $invoiceId): array
    {
        $invoice = LicenseInvoice::query()->whereKey($invoiceId)->firstOrFail();
        $payment = LicensePayment::query()
            ->where('license_invoice_id', $invoice->id)
            ->latest('id')
            ->first();

        $tenant = DB::connection('master')->table('tenants')->where('id', $invoice->tenant_id)->first();
        $plan = DB::connection('master')->table('plans')->where('id', $invoice->plan_id)->first();
        $branding = DB::connection('master')->table('central_branding')->first();

        $baseCurrency = $invoice->currency ?: $this->baseCurrency();
        $chargeCurrency = $invoice->charge_currency ?: $baseCurrency;

        $statusMap = [
            LicenseInvoice::STATUS_INVOICE_PENDING => ['Awaiting Payment', '#b45309', '#fef3c7'],
            LicenseInvoice::STATUS_PAYMENT_PENDING => ['Payment Pending', '#b45309', '#fef3c7'],
            LicenseInvoice::STATUS_PAYMENT_CONFIRMED => ['Paid', '#047857', '#d1fae5'],
            LicenseInvoice::STATUS_LICENSE_ACTIVATED => ['Paid', '#047857', '#d1fae5'],
            LicenseInvoice::STATUS_FAILED => ['Failed', '#b91c1c', '#fee2e2'],
            LicenseInvoice::STATUS_CANCELLED => ['Cancelled', '#6b7280', '#f3f4f6'],
        ];
        [$statusLabel, $statusColor, $statusBg] = $statusMap[$invoice->status] ?? ['Pending', '#b45309', '#fef3c7'];

        $logoPath = ($branding && ! empty($branding->logo_path)) ? public_path('storage/'.$branding->logo_path) : null;

        return [
            'platform_name' => $branding->platform_name ?? config('app.name', 'Mfuko Pro'),
            'platform_tagline' => $branding->tagline ?? 'Sacco Management Platform',
            'logo_path' => ($logoPath && is_file($logoPath)) ? $logoPath : null,
            'invoice' => $invoice,
            'payment' => $payment,
            'tenant_name' => $tenant->name ?? '—',
            'tenant_code' => $tenant->subdomain ?? null,
            'plan_name' => $plan->name ?? ucfirst((string) $invoice->billing_cycle),
            'billing_cycle' => ucfirst((string) $invoice->billing_cycle),
            'base_currency' => $baseCurrency,
            'charge_currency' => $chargeCurrency,
            'fx_rate' => (float) $invoice->fx_rate,
            'is_converted' => strtoupper($chargeCurrency) !== strtoupper($baseCurrency),
            'payment_method_label' => $payment ? $this->paymentMethodLabel($payment->payment_method) : '—',
            'status_label' => $statusLabel,
            'status_color' => $statusColor,
            'status_bg' => $statusBg,
            'subtotal' => $this->formatMoney($invoice->subtotal, $baseCurrency),
            'service_fee' => $this->formatMoney($invoice->service_fee, $baseCurrency),
            'total' => $this->formatMoney($invoice->total, $baseCurrency),
            'charge_total' => $this->formatMoney($invoice->charge_total ?? $invoice->total, $chargeCurrency),
            'fx_rate_label' => '1 '.$baseCurrency.' = '.rtrim(rtrim(number_format($invoice->fx_rate, 6), '0'), '.').' '.$chargeCurrency,
            'issued_at' => optional($invoice->created_at)->format('d M Y'),
            'due_at' => optional($invoice->due_at)->format('d M Y'),
            'paid_at' => optional($invoice->paid_at)->format('d M Y, H:i'),
            'renewal_start' => optional($invoice->renewal_starts_at)->format('d M Y'),
            'renewal_end' => optional($invoice->renewal_expires_at)->format('d M Y'),
            'generated_at' => now()->format('d M Y, H:i'),
        ];
    }

    public function emailLicenseInvoice(array $input): mixed
    {
        $data = Validator::make($input, [
            'invoice_id' => 'required|string|exists:master.license_invoices,id',
            'email' => 'required|email',
            'message' => 'nullable|string|max:1000',
        ])->validate();

        return $this->TryCatch(function () use ($data): array {
            $document = $this->licenseInvoiceDocumentData($data['invoice_id']);

            Mail::to($data['email'])->send(new LicenseInvoiceMail($document, $data['message'] ?? null));

            return [
                'invoice_id' => (string) $document['invoice']->id,
                'invoice_number' => $document['invoice']->invoice_number,
                'emailed_to' => $data['email'],
                'message' => 'Invoice emailed successfully.',
            ];
        });
    }

    private function paymentMethodLabel(?string $method): string
    {
        return [
            'mobile_money' => 'Mobile Money',
            'card' => 'Card',
            'bank' => 'Bank Transfer',
        ][$method] ?? ($method ? ucfirst($method) : '—');
    }

    private function formatMoney(mixed $amount, string $currency): string
    {
        return strtoupper($currency).' '.number_format((float) $amount, $this->currencyDecimals($currency));
    }

    public function confirmLicensePayment(array $input): mixed
    {
        return $this->TryCatch(function () use ($input): array {
            $data = Validator::make($input, [
                'invoice_id' => 'required|integer|exists:master.license_invoices,id',
                'payment_id' => 'nullable|integer|exists:master.license_payments,id',
                'approval_type' => 'required|string|in:manual,gateway_callback',
                'provider_reference' => 'nullable|string|max:160',
                'gateway_payload' => 'nullable|array',
                'failure_reason' => 'nullable|string|max:2000',
            ])->validate();

            if (($data['approval_type'] ?? null) === 'gateway_callback' && empty($data['provider_reference'])) {
                throw ValidationException::withMessages([
                    'provider_reference' => ['Provider reference is required for gateway confirmations.'],
                ]);
            }

            return DB::connection('master')->transaction(function () use ($data): array {
                $now = now();
                $actorId = auth('sanctum')->id() ?? auth('platform')->id();

                $invoice = LicenseInvoice::query()
                    ->whereKey($data['invoice_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $payment = LicensePayment::query()
                    ->where('license_invoice_id', $invoice->id)
                    ->when(! empty($data['payment_id']), fn ($query) => $query->whereKey($data['payment_id']))
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($invoice->status === LicenseInvoice::STATUS_LICENSE_ACTIVATED && $invoice->activated_license_id) {
                    return [
                        'invoice_id' => (string) $invoice->id,
                        'payment_id' => (string) $payment->id,
                        'new_license_id' => (string) $invoice->activated_license_id,
                        'invoice_status' => $invoice->status,
                        'payment_status' => $payment->status,
                        'message' => 'License was already activated for this invoice.',
                    ];
                }

                $payment->update([
                    'provider_reference' => $data['provider_reference'] ?? $payment->provider_reference,
                    'status' => LicensePayment::STATUS_PAYMENT_CONFIRMED,
                    'paid_at' => $payment->paid_at ?: $now,
                    'gateway_payload' => $data['gateway_payload'] ?? $payment->gateway_payload,
                    'failure_reason' => $data['failure_reason'] ?? null,
                    'confirmed_at' => $payment->confirmed_at ?: $now,
                    'approved_by' => $actorId,
                    'approved_at' => $now,
                    'updated_by' => $actorId,
                ]);

                LicensePaymentAttempt::create([
                    'license_payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'provider_reference' => $payment->provider_reference,
                    'idempotency_key' => 'confirm-'.$payment->id.'-'.(string) Str::uuid(),
                    'status' => LicensePayment::STATUS_PAYMENT_CONFIRMED,
                    'response_payload' => $data['gateway_payload'] ?? null,
                    'failure_reason' => $data['failure_reason'] ?? null,
                    'attempted_at' => $now,
                    'confirmed_at' => $now,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                ]);

                $invoice->update([
                    'status' => LicenseInvoice::STATUS_PAYMENT_CONFIRMED,
                    'paid_at' => $invoice->paid_at ?: $now,
                    'gateway_payload' => $data['gateway_payload'] ?? $invoice->gateway_payload,
                    'failure_reason' => $data['failure_reason'] ?? null,
                    'approved_by' => $actorId,
                    'approved_at' => $now,
                    'updated_by' => $actorId,
                ]);

                $renewedLicense = License::query()
                    ->whereKey($invoice->license_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Keep a single current license per tenant: expire any *other*
                // live licenses, then renew this one in place.
                License::query()
                    ->where('tenant_id', $invoice->tenant_id)
                    ->whereKeyNot($renewedLicense->id)
                    ->whereIn('status', ['active', 'trial', 'grace', 'suspended'])
                    ->update(['status' => 'expired']);

                $renewedLicense->update([
                    'plan_id' => $invoice->plan_id,
                    'plan' => (string) $invoice->plan_id,
                    'starts_at' => $invoice->renewal_starts_at?->toDateString()
                        ?? Carbon::parse($renewedLicense->expires_at)->addDay()->toDateString(),
                    'expires_at' => $invoice->renewal_expires_at?->toDateString()
                        ?? Carbon::parse($renewedLicense->expires_at)->addYear()->toDateString(),
                    'grace_ends_at' => null,
                    'status' => 'active',
                ]);

                $invoice->update([
                    'activated_license_id' => $renewedLicense->id,
                    'status' => LicenseInvoice::STATUS_LICENSE_ACTIVATED,
                    'updated_by' => $actorId,
                ]);

                Tenant::query()
                    ->whereKey($invoice->tenant_id)
                    ->update(['status' => 'active']);

                return [
                    'invoice_id' => (string) $invoice->id,
                    'payment_id' => (string) $payment->id,
                    'new_license_id' => (string) $renewedLicense->id,
                    'renewed_license_id' => (string) $renewedLicense->id,
                    'invoice_status' => LicenseInvoice::STATUS_LICENSE_ACTIVATED,
                    'payment_status' => $payment->fresh()->status,
                    'message' => 'Payment confirmed and license renewed.',
                ];
            });
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
            ->join('plans', 'plans.id', '=', 'licenses.plan_id')
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
            ->leftJoin('plans as pl', 'pl.id', '=', 'ls.plan_id');
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
        $window = self::EXPIRING_SOON_DAYS;

        return DB::raw("
            CASE
                WHEN ls.status = 'expired'
                    OR (ls.status IN ('active', 'trial') AND DATE(ls.expires_at) < CURDATE())
                    THEN 'expired'
                WHEN ls.status IN ('active', 'trial')
                    AND DATE(ls.expires_at) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$window} DAY)
                    THEN 'expiring_soon'
                ELSE ls.status
            END AS status_state
        ");
    }

    private function statusLabelSelect(): Expression
    {
        $window = self::EXPIRING_SOON_DAYS;

        return DB::raw("
            CASE
                WHEN ls.status = 'expired'
                    OR (ls.status IN ('active', 'trial') AND DATE(ls.expires_at) < CURDATE())
                    THEN 'Expired'
                WHEN ls.status IN ('active', 'trial')
                    AND DATE(ls.expires_at) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$window} DAY)
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
            ->leftJoin('plans as pl', 'pl.id', '=', 'ls.plan_id')
            ->where('ls.id', $id)
            ->first([
                'ls.id',
                'ls.tenant_id',
                'ts.name AS tenant_name',
                'ts.subdomain AS tenant_code',
                DB::raw('COALESCE(ls.plan_id, ls.plan) AS plan_id'),
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

    /**
     * The platform settlement currency — the currency plan prices are
     * denominated in and invoices are recorded in.
     */
    private function baseCurrency(): string
    {
        $currency = rescue(
            fn () => DB::connection('master')->table('central_currency_settings')->value('default_currency'),
            null,
            false
        );

        return strtoupper((string) ($currency ?: 'UGX'));
    }

    /** Currencies the platform has switched on for presentment. */
    private function enabledCurrencies(): array
    {
        $value = rescue(
            fn () => DB::connection('master')->table('central_currency_settings')->value('enabled_currencies'),
            null,
            false
        );
        $list = is_string($value) ? json_decode($value, true) : $value;
        $list = is_array($list) ? array_map('strtoupper', $list) : [];

        return $list !== [] ? array_values(array_unique($list)) : [$this->baseCurrency()];
    }

    private function ratePerUsd(string $currency): float
    {
        $rates = array_change_key_case((array) config('billing.currency_rates', []), CASE_UPPER)
            + self::REFERENCE_RATES_PER_USD;

        return (float) ($rates[strtoupper($currency)] ?? 0.0);
    }

    private function currencyDecimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
    }

    /** Exchange rate that converts 1 unit of $base into $charge. */
    private function exchangeRate(string $base, string $charge): float
    {
        if (strtoupper($base) === strtoupper($charge)) {
            return 1.0;
        }

        $baseRate = $this->ratePerUsd($base);
        $chargeRate = $this->ratePerUsd($charge);

        if ($baseRate <= 0 || $chargeRate <= 0) {
            throw ValidationException::withMessages([
                'currency' => ["No reference exchange rate is configured to convert {$base} to {$charge}."],
            ]);
        }

        return $chargeRate / $baseRate;
    }

    private function convertAmount(float $baseAmount, float $rate, string $chargeCurrency): float
    {
        return round($baseAmount * $rate, $this->currencyDecimals($chargeCurrency));
    }

    /**
     * Convert a plan's USD-denominated price into the platform settlement
     * (base) currency — e.g. a $149 plan becomes UGX 556,515 when the base
     * currency is UGX.
     */
    private function planAmountInBaseCurrency(object $plan): float
    {
        $price = (float) ($plan->price ?? 0);
        $baseCurrency = $this->baseCurrency();
        $rate = $this->exchangeRate(self::PLAN_PRICING_CURRENCY, $baseCurrency);

        return $this->convertAmount($price, $rate, $baseCurrency);
    }

    /**
     * Determine and validate the currency the payment is actually charged in,
     * honouring the constraints of the selected payment method:
     *  - Mobile money (MTN/Airtel, Uganda) settles in UGX only.
     *  - Card / bank present in the requested currency when it is enabled,
     *    otherwise the platform settlement (base) currency.
     */
    private function resolveChargeCurrency(string $method, ?string $requested, string $baseCurrency, array $enabled): string
    {
        $requested = $requested ? strtoupper(trim($requested)) : null;

        if ($method === 'mobile_money') {
            if ($requested && $requested !== self::MOBILE_MONEY_CURRENCY) {
                throw ValidationException::withMessages([
                    'currency' => ['Mobile money payments can only be settled in '.self::MOBILE_MONEY_CURRENCY.'.'],
                ]);
            }

            return self::MOBILE_MONEY_CURRENCY;
        }

        $charge = $requested ?: $baseCurrency;

        if (! in_array($charge, $enabled, true)) {
            throw ValidationException::withMessages([
                'currency' => ["{$charge} is not an enabled currency. Enable it under Central → Currency settings first."],
            ]);
        }

        return $charge;
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
            'currency' => $this->baseCurrency(),
            'base_currency' => $this->baseCurrency(),
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
        $planId = $this->resolvePlanId($planIdentifier);

        return License::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $planId,
            'plan' => $planId,
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
