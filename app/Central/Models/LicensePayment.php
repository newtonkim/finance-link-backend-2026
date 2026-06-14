<?php

namespace App\Central\Models;

use App\Concerns\HasDynamicConnection;
use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LicensePayment extends Model
{
    use HasDynamicConnection;

    public const STATUS_PAYMENT_PENDING = 'payment_pending';

    public const STATUS_PAYMENT_CONFIRMED = 'payment_confirmed';

    public const STATUS_PAYMENT_FAILED = 'payment_failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $connection = 'master';

    protected $fillable = [
        'license_invoice_id',
        'tenant_id',
        'amount',
        'currency',
        'base_currency',
        'base_amount',
        'fx_rate',
        'payment_method',
        'provider',
        'provider_reference',
        'idempotency_key',
        'phone_number',
        'account_name',
        'save_payment_method',
        'status',
        'paid_at',
        'gateway_payload',
        'failure_reason',
        'confirmed_at',
        'approved_by',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'fx_rate' => 'decimal:8',
        'save_payment_method' => 'boolean',
        'paid_at' => 'datetime',
        'gateway_payload' => 'array',
        'confirmed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(LicenseInvoice::class, 'license_invoice_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LicensePaymentAttempt::class);
    }
}
