<?php

namespace App\Tenant\Modules\Savings\Models;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SavingsProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return \Database\Factories\SavingsProductFactory::new();
    }

    protected $connection = 'tenant';

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'type',
        'minimum_balance',
        'minimum_maturity_months',
        'dormancy_period_months',
        'charge_on_deposit',
        'charge_on_withdraw',
        'charge_on_transfer',
        'status',
        'monthly_fee_enabled',
        'monthly_fee_type',
        'monthly_fee_amount',
        'monthly_fee_deduction_day',
        // Loyalty fields
        'loyalty_fee_enabled',
        'loyalty_adjustment_type',
        'loyalty_adjustment_value',
        // FD fields
        'interest_rate',
        'interest_payout_type',
        'interest_posting_frequency',
        'default_tenor_months',
        'maturity_action',
        'convert_to_product_id',
        'interest_expense_account_id',
        'interest_payable_account_id',
        'interest_enabled',
    ];

    protected $casts = [
        'minimum_balance' => 'decimal:2',
        'minimum_maturity_months' => 'integer',
        'dormancy_period_months' => 'integer',
        'charge_on_deposit' => 'boolean',
        'charge_on_withdraw' => 'boolean',
        'charge_on_transfer' => 'boolean',
        'monthly_fee_enabled' => 'boolean',
        'monthly_fee_amount' => 'decimal:2',
        'monthly_fee_deduction_day' => 'integer',
        'loyalty_fee_enabled' => 'boolean',
        'loyalty_adjustment_value' => 'decimal:2',
        // FD casts
        'interest_rate' => 'decimal:4',
        'default_tenor_months' => 'integer',
        'convert_to_product_id' => 'integer',
        'interest_expense_account_id' => 'integer',
        'interest_payable_account_id' => 'integer',
        'interest_enabled' => 'boolean',
    ];

    public function charges(): HasMany
    {
        return $this->hasMany(SavingsProductCharge::class);
    }

    public function convertToProduct(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class, 'convert_to_product_id');
    }

    public function interestExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'interest_expense_account_id');
    }

    public function interestPayableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'interest_payable_account_id');
    }

    public function isFixed(): bool
    {
        return $this->type === 'fixed';
    }
}
