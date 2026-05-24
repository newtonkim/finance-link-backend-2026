<?php

namespace App\Tenant\Modules\Savings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsInterestPosting extends Model
{
    protected $connection = 'tenant';

    public $timestamps = false;

    protected $fillable = [
        'savings_account_id',
        'period_start',
        'period_end',
        'principal',
        'rate',
        'interest_amount',
        'payout_type',
        'journal_entry_id',
        'posted_by',
        'created_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'principal' => 'decimal:2',
        'rate' => 'decimal:4',
        'interest_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
    }
}
