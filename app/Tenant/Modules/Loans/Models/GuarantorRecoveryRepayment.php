<?php

namespace App\Tenant\Modules\Loans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A borrower's repayment of a recovery loan, split across the guarantors it reimburses. */
class GuarantorRecoveryRepayment extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'guarantor_recovery_id',
        'amount',
        'payment_date',
        'payment_mode',
        'reference',
        'allocations',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'allocations' => 'array',
    ];

    public function recovery(): BelongsTo
    {
        return $this->belongsTo(GuarantorRecovery::class, 'guarantor_recovery_id');
    }
}
