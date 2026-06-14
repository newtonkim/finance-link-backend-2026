<?php

namespace App\Central\Models;

use App\Concerns\HasDynamicConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicensePaymentAttempt extends Model
{
    use HasDynamicConnection;

    protected $connection = 'master';

    protected $fillable = [
        'license_payment_id',
        'provider',
        'provider_reference',
        'idempotency_key',
        'status',
        'request_payload',
        'response_payload',
        'failure_reason',
        'attempted_at',
        'confirmed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'attempted_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(LicensePayment::class, 'license_payment_id');
    }
}
