<?php

namespace App\Tenant\Modules\Expenses\Models;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseApprovalHistory extends Model
{
    protected $connection = 'tenant';
    protected $table = 'expense_approval_history';

    protected $fillable = [
        'expense_id',
        'approver_id',
        'level',
        'action',
        'comments',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approver_id');
    }
}
