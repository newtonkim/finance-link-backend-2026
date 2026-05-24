<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanReschedule extends Model
{
    protected $connection = 'tenant';

    protected $table = 'loan_rescheduling';

    protected $fillable = [
        'reschedule_id',
        'original_loan_id',
        'new_loan_id',
        'reschedule_date',
        'reschedule_type',
        'old_status',
        'old_outstanding',
        'old_interest_rate',
        'old_remaining_periods',
        'old_maturity_date',
        'new_principal',
        'new_rate',
        'new_duration',
        'new_maturity_date',
        'capitalized_arrears',
        'capitalized_interest',
        'penalties_waived',
        'interest_waived',
        'reason',
        'approved_by',
        'performed_by',
        'fees_applied',
        'old_product_id',
        'new_product_id',
    ];

    protected $casts = [
        'reschedule_date' => 'date',
        'old_outstanding' => 'decimal:2',
        'old_interest_rate' => 'decimal:2',
        'old_remaining_periods' => 'integer',
        'old_maturity_date' => 'date',
        'new_principal' => 'decimal:2',
        'new_rate' => 'decimal:2',
        'new_duration' => 'integer',
        'new_maturity_date' => 'date',
        'capitalized_arrears' => 'decimal:2',
        'capitalized_interest' => 'decimal:2',
        'penalties_waived' => 'decimal:2',
        'interest_waived' => 'decimal:2',
        'fees_applied' => 'array',
        'old_product_id' => 'integer',
        'new_product_id' => 'integer',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'original_loan_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'performed_by');
    }

    public function approvedByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by');
    }
}
