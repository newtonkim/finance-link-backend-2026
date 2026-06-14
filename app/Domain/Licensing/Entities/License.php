<?php

namespace App\Domain\Licensing\Entities;

use App\Concerns\HasDynamicConnection;
use App\Domain\Tenancy\Entities\Tenant;
use Database\Factories\LicenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class License extends Model
{
    use HasDynamicConnection, HasFactory;

    protected static function newFactory()
    {
        return LicenseFactory::new();
    }

    protected $connection = 'master';

    protected static function booted()
    {
        static::creating(function ($license) {
            if (! $license->id) {
                $license->id = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'plan',
        'starts_at',
        'expires_at',
        'grace_ends_at',
        'max_members',
        'max_users',
        'features',
        'status',
    ];

    /**
     * Primary key configuration for UUID
     */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'id' => 'string',
        'starts_at' => 'date',
        'expires_at' => 'date',
        'grace_ends_at' => 'date',
        'features' => 'json',
        'plan_id' => 'integer',
        'max_members' => 'integer',
        'max_users' => 'integer',
    ];

    public function isExpired(): bool
    {
        return now()->greaterThan($this->expires_at);
    }

    public function isExpiringSoon(int $days = 3): bool
    {
        return now()->addDays($days)->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Check if the license is currently in the grace period.
     * Grace period = license has expired but grace_ends_at hasn't passed yet.
     */
    public function isInGracePeriod(): bool
    {
        if (! $this->grace_ends_at) {
            return false;
        }

        return $this->isExpired() && now()->lessThanOrEqualTo($this->grace_ends_at);
    }

    /**
     * Check if a specific feature is enabled on this license.
     */
    public function hasFeature(string $feature): bool
    {
        return isset($this->features[$feature]) && $this->features[$feature];
    }

    /**
     * Get the tenant that owns the license.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(\App\Central\Models\Plan::class);
    }
}
