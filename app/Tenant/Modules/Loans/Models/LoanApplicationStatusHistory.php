<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanApplicationStatusHistory extends Model
{
    protected $connection = 'tenant';

    protected $table = 'loan_application_status_history';

    protected $fillable = [
        'loan_application_id',
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

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'changed_by');
    }
}
