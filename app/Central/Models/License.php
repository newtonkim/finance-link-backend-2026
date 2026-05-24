<?php

namespace App\Central\Models;

use App\Concerns\HasDynamicConnection;
use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class License extends Model
{
    use HasDynamicConnection, HasUuids;

    protected $connection = 'master';

    protected $fillable = [
        'tenant_id',
        'plan',
        'starts_at',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan', 'slug');
    }
}
