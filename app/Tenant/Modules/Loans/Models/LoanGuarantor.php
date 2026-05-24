<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Member;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanGuarantor extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'loan_id',
        'loan_application_id',
        'member_id',
        'branch_id',
        'guarantee_amount',
        'guarantee_type',
        'max_guarantee_used',
        'accepted_date',
        'released_date',
        'status',
        'approved_by',
        'notes',
    ];

    protected $casts = [
        'loan_id' => 'integer',
        'loan_application_id' => 'integer',
        'member_id' => 'integer',
        'branch_id' => 'integer',
        'guarantee_amount' => 'decimal:2',
        'max_guarantee_used' => 'decimal:2',
        'accepted_date' => 'date',
        'released_date' => 'date',
        'approved_by' => 'integer',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function loanApplication(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by');
    }
}
