<?php

namespace App\Central\Models;

use App\Concerns\HasDynamicConnection;
use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LicenseInvoice extends Model
{
    use HasDynamicConnection;

    public const STATUS_INVOICE_PENDING = 'invoice_pending';

    public const STATUS_PAYMENT_PENDING = 'payment_pending';

    public const STATUS_PAYMENT_CONFIRMED = 'payment_confirmed';

    public const STATUS_LICENSE_ACTIVATED = 'license_activated';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $connection = 'master';

    protected $fillable = [
        'license_id',
        'tenant_id',
        'plan_id',
        'billing_cycle',
        'invoice_number',
        'idempotency_key',
        'currency',
        'charge_currency',
        'fx_rate',
        'charge_total',
        'subtotal',
        'service_fee',
        'total',
        'status',
        'due_at',
        'renewal_starts_at',
        'renewal_expires_at',
        'activated_license_id',
        'paid_at',
        'gateway_payload',
        'failure_reason',
        'approved_by',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'service_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'fx_rate' => 'decimal:8',
        'charge_total' => 'decimal:2',
        'due_at' => 'date',
        'renewal_starts_at' => 'date',
        'renewal_expires_at' => 'date',
        'paid_at' => 'datetime',
        'gateway_payload' => 'array',
        'approved_at' => 'datetime',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function activatedLicense(): BelongsTo
    {
        return $this->belongsTo(License::class, 'activated_license_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LicensePayment::class);
    }
}
