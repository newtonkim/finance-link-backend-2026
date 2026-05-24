<?php

namespace App\Tenant\Modules\Savings\Models;

use App\Models\Concerns\BelongsToAuthenticatedBranch;
use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SavingsAccount extends Model
{
    use BelongsToAuthenticatedBranch, HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return \Database\Factories\SavingsAccountFactory::new();
    }

    protected $connection = 'tenant';

    protected $fillable = [
        'member_id',
        'savings_product_id',
        'account_no',
        'code',
        'account_type',
        'is_new_account',
        'balance',
        'initial_deposit',
        'consider_min_balance',
        'interest_rate',
        'status',
        'selected_charges',
        'custom_monthly_fee_enabled',
        'custom_monthly_fee_type',
        'custom_monthly_fee_amount',
        'branch_id',
        'tenor_months',
        'maturity_date',
        'next_interest_date',
        'maturity_action',
        'payout_savings_account_id',
        'last_interest_posted_at',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'initial_deposit' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'is_new_account' => 'boolean',
        'consider_min_balance' => 'boolean',
        'selected_charges' => 'array',
        'custom_monthly_fee_enabled' => 'boolean',
        'custom_monthly_fee_amount' => 'decimal:2',
        'branch_id' => 'integer',
        'tenor_months' => 'integer',
        'maturity_date' => 'date',
        'next_interest_date' => 'date',
        'payout_savings_account_id' => 'integer',
        'last_interest_posted_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function savingsProduct(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class);
    }

    public function payoutSavingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'payout_savings_account_id');
    }

    public function interestPostings(): HasMany
    {
        return $this->hasMany(SavingsInterestPosting::class);
    }

    public function isFixed(): bool
    {
        return $this->account_type === 'fixed';
    }
}
