<?php

namespace App\Tenant\Modules\Members\Models;

use App\Models\Member;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberCharge extends Model
{
    protected $connection = 'tenant';

    protected $table = 'member_charges';

    protected $fillable = [
        'member_id',
        'general_charge_id',
        'savings_account_id',
        'charge_name',
        'amount',
        'status',
        'due_date',
        'applied_at',
        'paid_at',
        'transaction_id',
        'narration',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'applied_at' => 'datetime',
        'paid_at' => 'datetime',
        'due_date' => 'date',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function generalCharge(): BelongsTo
    {
        return $this->belongsTo(GeneralCharge::class);
    }

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
