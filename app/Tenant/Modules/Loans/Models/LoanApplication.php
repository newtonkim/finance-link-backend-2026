<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Member;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanApplication extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';

    // Status constants
    const STATUS_DRAFT = 'draft';

    const STATUS_SUBMITTED = 'submitted';

    const STATUS_UNDER_REVIEW = 'under_review';

    const STATUS_AWAITING_DOCUMENTS = 'awaiting_documents';

    const STATUS_AWAITING_GUARANTORS = 'awaiting_guarantors';

    const STATUS_RECOMMENDED = 'recommended';

    const STATUS_APPROVED = 'approved';

    const STATUS_REJECTED = 'rejected';

    const STATUS_DISBURSEMENT_PENDING = 'disbursement_pending';

    const STATUS_DISBURSED = 'disbursed';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_OFFICER_RECOMMENDED = 'officer_recommended';

    const STATUS_BM_RECOMMENDED = 'bm_recommended';

    const STATUS_COMMITTEE_VOTING = 'committee_voting';

    const STATUS_DECLINED = 'declined';

    const STATUS_RETURNED_FOR_CORRECTION = 'returned_for_correction';

    protected $fillable = [
        'application_no',
        'member_id',
        'loan_product_id',
        'branch_id',
        'loan_officer_id',
        'requested_amount',
        'requested_term',
        'purpose',
        'repayment_source',
        'status',
        'recommended_amount',
        'recommended_term',
        'recommended_interest_rate',
        'approved_amount',
        'approved_term',
        'approved_interest_rate',
        'rejection_reason',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by',
        'appraisal_notes',
        'approval_notes',
        'appraised_by',
        'recommended_by',
        'approved_by',
        'rejected_by',
        'disbursed_loan_id',
        'submitted_at',
        'recommended_at',
        'approved_at',
        'rejected_at',
        'disbursed_at',
        'created_by',
        'updated_by',
        'deleted_by',
        'system_type',
        'risk_rating',
        'reviewed_at',
        'reviewed_by',
        'return_reason',
        'returned_at',
        'returned_by',
        'officer_notes',
        'bm_notes',
        'correction_reason',
        'quorum_required',
        'approval_threshold',
        'unanimity_required',
        'final_approved_amount',
        'final_approved_term',
        'proposed_start_date',
        'schedule_date',
        'schedule_locked_at',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'recommended_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'requested_term' => 'integer',
        'recommended_term' => 'integer',
        'recommended_interest_rate' => 'decimal:2',
        'approved_term' => 'integer',
        'approved_interest_rate' => 'decimal:2',
        'member_id' => 'integer',
        'loan_product_id' => 'integer',
        'branch_id' => 'integer',
        'loan_officer_id' => 'integer',
        'appraised_by' => 'integer',
        'recommended_by' => 'integer',
        'approved_by' => 'integer',
        'rejected_by' => 'integer',
        'disbursed_loan_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'submitted_at' => 'datetime',
        'recommended_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'cancelled_by' => 'integer',
        'reviewed_at' => 'datetime',
        'reviewed_by' => 'integer',
        'returned_at' => 'datetime',
        'returned_by' => 'integer',
        'quorum_required' => 'integer',
        'approval_threshold' => 'integer',
        'unanimity_required' => 'boolean',
        'final_approved_amount' => 'decimal:2',
        'final_approved_term' => 'integer',
        'proposed_start_date' => 'date',
        'schedule_date' => 'date',
        'schedule_locked_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function loanOfficer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'loan_officer_id');
    }

    public function appraisedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'appraised_by');
    }

    public function recommendedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'recommended_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'rejected_by');
    }

    public function disbursedLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'disbursed_loan_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(LoanApplicationStatusHistory::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LoanApplicationDocument::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LoanApplicationApproval::class);
    }

    public function collaterals(): HasMany
    {
        return $this->hasMany(LoanApplicationCollateral::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    /**
     * Include soft-deleted records in route model binding so that disbursed,
     * cancelled, or otherwise soft-deleted applications are still resolvable
     * when the frontend navigates to their detail pages or sub-routes.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return parent::resolveRouteBindingQuery($query, $value, $field)->withTrashed();
    }

    /**
     * Get statuses that are considered "Pending" (not yet approved or disbursed).
     */
    public static function getPendingStatuses(): array
    {
        return [
            self::STATUS_SUBMITTED,
            self::STATUS_UNDER_REVIEW,
            self::STATUS_RECOMMENDED,
            self::STATUS_AWAITING_DOCUMENTS,
            self::STATUS_AWAITING_GUARANTORS,
            self::STATUS_OFFICER_RECOMMENDED,
            self::STATUS_BM_RECOMMENDED,
            self::STATUS_COMMITTEE_VOTING,
            self::STATUS_RETURNED_FOR_CORRECTION,
            self::STATUS_DISBURSEMENT_PENDING,
        ];
    }
}
