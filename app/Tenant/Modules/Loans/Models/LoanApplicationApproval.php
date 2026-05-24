<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanApplicationApproval extends Model
{
    protected $connection = 'tenant';

    protected $table = 'loan_application_approvals';

    protected $fillable = [
        'loan_application_id',
        'approver_id',
        'level',
        'decision',
        'comments',
        'decided_at',
    ];

    protected $casts = [
        'loan_application_id' => 'integer',
        'approver_id' => 'integer',
        'level' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function loanApplication(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approver_id');
    }
}
