<?php

namespace App\Central\Models;

use App\Concerns\HasDynamicConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasDynamicConnection;

    protected $connection = 'master';

    protected $fillable = [
        'name',
        'slug',
        'price',
        'billing_cycle',
        'max_members',
        'max_users',
        'features',
    ];

    protected $casts = [
        'features' => 'json',
        'price' => 'decimal:2',
        'max_members' => 'integer',
        'max_users' => 'integer',
    ];

    public function hasFeature(string $feature): bool
    {
        $features = $this->features;

        // Older default plans were JSON-encoded before the model's JSON cast,
        // leaving a JSON string after Eloquent decoded the database value.
        if (is_string($features)) {
            $features = json_decode($features, true);
        }

        if (! is_array($features)) {
            return false;
        }

        return filter_var($features[$feature] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class, 'plan_id');
    }
}
