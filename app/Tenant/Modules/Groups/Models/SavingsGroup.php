<?php

namespace App\Tenant\Modules\Groups\Models;

use App\Models\Member;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Concerns\HasDynamicConnection;

class SavingsGroup extends Model
{
    use HasFactory, SoftDeletes, HasDynamicConnection;

    protected static function newFactory()
    {
        return \Database\Factories\SavingsGroupFactory::new();
    }

    protected $connection = 'tenant';

    protected $fillable = [
        'code',
        'name',
        'image_path',
        'primary_contact_country_code',
        'primary_contact_phone',
        'other_contact_country_code',
        'other_contact_phone',
        'date_created',
        'location',
        'description',
        'status',
        'created_by',
    ];

    protected $casts = [
        'date_created' => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'savings_group_members')
            ->withPivot('role', 'account_number')
            ->withTimestamps();
    }

    /**
     * Scope a query to only include active groups.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
