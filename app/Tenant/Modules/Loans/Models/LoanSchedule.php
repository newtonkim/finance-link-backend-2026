<?php

namespace App\Tenant\Modules\Loans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

 /** @property int $id
 * @mixin \Eloquent
 * @mixin \Illuminate\Database\Eloquent\Builder @mixin \Illuminate\Database\Query\Builder */ class LoanSchedule extends Model
{
    protected $connection = 'tenant';

    protected $table = 'loan_repayment_schedule';

    protected $fillable = [
        'loan_id',
        'reschedule_id',
        'schedule_id',
        'due_date',
        'installment_no',
        'principal_due',
        'interest_due',
        'charges_due',
        'penalty_due',
        'total_due',
        'principal_paid',
        'interest_paid',
        'charges_paid',
        'penalty_paid',
        'outstanding_balance',
        'paid_at',
        'paid_date',
        'status',
        'last_arrears_tier_id',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_at' => 'date',
        'paid_date' => 'date',
        'principal_due' => 'decimal:2',
        'interest_due' => 'decimal:2',
        'charges_due' => 'decimal:2',
        'penalty_due' => 'decimal:2',
        'total_due' => 'decimal:2',
        'principal_paid' => 'decimal:2',
        'interest_paid' => 'decimal:2',
        'charges_paid' => 'decimal:2',
        'penalty_paid' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function getTotalPaidAttribute(): float
    {
        return (float) $this->principal_paid
            + (float) $this->interest_paid
            + (float) $this->charges_paid
            + (float) $this->penalty_paid;
    }

    public function getAmountDueAttribute(): float
    {
        return (float) $this->total_due
            + (float) $this->penalty_due
            + (float) $this->charges_due;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status !== 'paid' && $this->due_date->isPast();
    }

    public function getDaysOverdueAttribute(): int
    {
        if (! $this->is_overdue) {
            return 0;
        }

        return (int) $this->due_date->diffInDays(now());
    }

    public function reschedule(): BelongsTo
    {
        return $this->belongsTo(LoanReschedule::class, 'reschedule_id');
    }
}
