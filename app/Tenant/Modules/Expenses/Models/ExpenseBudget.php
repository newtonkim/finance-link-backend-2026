<?php

namespace App\Tenant\Modules\Expenses\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExpenseBudget extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';
    
    protected $fillable = [
        'expense_category_id',
        'branch_id',
        'fiscal_year',
        'period_code',
        'allocated_amount',
        'spent_amount',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'spent_amount' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
