<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Member;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

 /** @property int $id
 * @mixin \Eloquent
 * @mixin \Illuminate\Database\Eloquent\Builder @mixin \Illuminate\Database\Query\Builder */ class LoanTransaction extends Model
{
    protected $connection = 'tenant';

    protected $table = 'loan_transactions';

    protected $fillable = [
        'payment_id',
        'loan_id',
        'reschedule_id',
        'member_id',
        'amount_paid',
        'principal_portion',
        'interest_portion',
        'penalty_portion',
        'charges_portion',
        'payment_date',
        'payment_method',
        'receipt_no',
        'collected_by',
        'reversal_flag',
        'reversed_by',
        'reversed_date',
        'transaction_ref',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'principal_portion' => 'decimal:2',
        'interest_portion' => 'decimal:2',
        'penalty_portion' => 'decimal:2',
        'charges_portion' => 'decimal:2',
        'payment_date' => 'date',
        'reversed_date' => 'datetime',
        'reversal_flag' => 'boolean',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function reschedule(): BelongsTo
    {
        return $this->belongsTo(LoanReschedule::class, 'reschedule_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'collected_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reversed_by');
    }
}
