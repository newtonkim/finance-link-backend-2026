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

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }
}
