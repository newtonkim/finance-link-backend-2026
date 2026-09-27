<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Member;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Recovering one defaulted loan from the borrower's savings and then its guarantors'
 * savings. Proposed by one staff member, approved (and so executed) by another.
 *
 * Once executed, guarantor_amount is a recovery loan the borrower owes the
 * guarantors, repaid in equal monthly instalments; repayments go back into the
 * guarantors' savings.
 */
class GuarantorRecovery extends Model
{
    protected $connection = 'tenant';

    const STATUS_PENDING_APPROVAL = 'pending_approval';

    const STATUS_EXECUTED = 'executed';

    const STATUS_REJECTED = 'rejected';

    const RECOVERY_LOAN_OPEN = 'open';

    const RECOVERY_LOAN_SETTLED = 'settled';

    protected $fillable = [
        'code',
        'loan_id',
        'member_id',
        'status',
        'requested_amount',
        'borrower_amount',
        'guarantor_amount',
        'notes',
        'initiated_by',
        'initiated_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'executed_at',
        'recovery_loan_status',
        'recovery_loan_repaid',
        'recovery_loan_term_months',
        'recovery_loan_first_due_date',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'borrower_amount' => 'decimal:2',
        'guarantor_amount' => 'decimal:2',
        'recovery_loan_repaid' => 'decimal:2',
        'initiated_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'executed_at' => 'datetime',
        'recovery_loan_first_due_date' => 'date',
        'recovery_loan_term_months' => 'integer',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GuarantorRecoveryLine::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(GuarantorRecoveryRepayment::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'initiated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by');
    }

    /** What the borrower still owes the guarantors. */
    public function recoveryLoanOutstanding(): float
    {
        return round(max(0, (float) $this->guarantor_amount - (float) $this->recovery_loan_repaid), 2);
    }
}
