<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

 /** @property int $id
 * @mixin \Eloquent
 * @mixin \Illuminate\Database\Eloquent\Builder @mixin \Illuminate\Database\Query\Builder */ class LoanStatusHistory extends Model
{
    protected $connection = 'tenant';

    protected $table = 'loan_status_histories';

    protected $fillable = [
        'loan_id',
        'from_status',
        'to_status',
        'changed_by',
        'notes',
        'ip_address',
        'changed_at',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'changed_by');
    }
}
