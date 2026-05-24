<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LoanCharge extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'loan_charges';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'category',
        'charge_type',
        'value',
        'frequency',
        'grace_days',
        'max_value',
        'max_value_type',
        'is_active',
        'income_account_id',
        'receivable_account_id',
        'description',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'max_value' => 'decimal:2',
        'grace_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'income_account_id');
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'receivable_account_id');
    }

    public function loanProducts(): BelongsToMany
    {
        return $this->belongsToMany(LoanProduct::class, 'loan_product_charge', 'loan_charge_id', 'loan_product_id')
            ->withTimestamps();
    }
}
