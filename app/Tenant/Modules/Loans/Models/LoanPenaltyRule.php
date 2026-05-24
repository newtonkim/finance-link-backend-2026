<?php

namespace App\Tenant\Modules\Loans\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanPenaltyRule extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'system_type',
        'loan_product_id',
        'penalty_type',
        'penalty_rate',
        'grace_days',
        'amount',
        'applies_to',
        'branch_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'loan_product_id' => 'integer',
        'penalty_rate' => 'decimal:2',
        'grace_days' => 'integer',
        'amount' => 'decimal:2',
        'branch_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
    ];

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class, 'loan_product_id');
    }
}
