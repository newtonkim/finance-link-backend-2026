<?php

namespace App\Tenant\Modules\Shares\Models;

use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Share extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';

    protected $fillable = [
        'member_id',
        'share_no',
        'share_value',
        'total_value',
        'purchased_at',
    ];

    protected $casts = [
        'share_value' => 'decimal:2',
        'total_value' => 'decimal:2',
        'purchased_at' => 'date',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
