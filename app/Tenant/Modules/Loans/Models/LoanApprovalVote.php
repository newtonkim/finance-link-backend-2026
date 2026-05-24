<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanApprovalVote extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'loan_application_id',
        'staff_id',
        'decision',
        'comment',
        'abstained',
        'abstained_by',
    ];

    protected $casts = [
        'loan_application_id' => 'integer',
        'staff_id' => 'integer',
        'abstained' => 'boolean',
        'abstained_by' => 'integer',
    ];

    public function loanApplication(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function abstainedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'abstained_by');
    }
}
