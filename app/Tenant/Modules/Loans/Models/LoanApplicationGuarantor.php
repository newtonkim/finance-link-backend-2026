<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One guarantee pledged on a loan application.
 *
 * guarantor_id points at members.id when guarantor_type is individual, and at
 * savings_groups.id when it is group. guarantee_amount is the pledged amount.
 */
class LoanApplicationGuarantor extends Model
{
    use SoftDeletes;

    protected $connection = 'tenant';

    const TYPE_INDIVIDUAL = 'individual';

    const TYPE_GROUP = 'group';

    /** Recorded, but the guarantor has not been asked to accept yet. */
    const STATUS_PROPOSED = 'proposed';

    /** The guarantor has been asked and has until consent_expires_at to answer. */
    const STATUS_REQUESTED = 'requested';

    const STATUS_ACCEPTED = 'accepted';

    const STATUS_DECLINED = 'declined';

    /** The guarantor did not answer in time. */
    const STATUS_EXPIRED = 'expired';

    const STATUS_WITHDRAWN = 'withdrawn';

    /** Standing behind a disbursed loan; the guarantor's savings are held for it. */
    const STATUS_LOCKED = 'locked';

    /** The loan was closed, so the guarantee no longer holds anything. */
    const STATUS_RELEASED = 'released';

    /** The SACCO took money from the guarantor's savings to repay the loan. */
    const STATUS_INVOKED = 'invoked';

    const CHANNEL_MEMBER_PORTAL = 'member_portal';

    const CHANNEL_OFFICER = 'officer';

    /**
     * Statuses whose pledge still stands: it holds the guarantor's capacity, and it
     * counts toward the application while consent is not required. When consent is
     * required only an accepted pledge counts toward the application.
     */
    const ACTIVE_STATUSES = [self::STATUS_PROPOSED, self::STATUS_REQUESTED, self::STATUS_ACCEPTED, self::STATUS_LOCKED];

    /** Statuses a guarantor (or an officer on their behalf) can still answer from. */
    const AWAITING_RESPONSE_STATUSES = [self::STATUS_PROPOSED, self::STATUS_REQUESTED];

    protected $fillable = [
        'code',
        'loan_application_id',
        'guarantor_id',
        'guarantor_account_id',
        'guarantor_type',
        'guarantee_amount',
        'recovered_amount',
        'max_guarantee_used',
        'status',
        'status_changed_at',
        'note',
        'system_type',
        'created_by',
        'updated_by',
        'approved_by',
        'accepted_date',
        'released_date',
        'requested_at',
        'consent_expires_at',
        'responded_at',
        'response_channel',
        'responded_by',
        'decline_reason',
        'consent_document_path',
        'loan_id',
        'locked_at',
        'released_at',
        'release_reason',
        'arrears_notified_at',
        'arrears_notice_count',
        'substitutes_id',
        'substituted_by_id',
        'release_requested_at',
        'release_request_reason',
        'reschedule_notified_at',
    ];

    protected $casts = [
        'loan_application_id' => 'integer',
        'guarantor_id' => 'integer',
        'guarantor_account_id' => 'integer',
        'guarantee_amount' => 'decimal:2',
        'recovered_amount' => 'decimal:2',
        'max_guarantee_used' => 'decimal:2',
        'status_changed_at' => 'datetime',
        'accepted_date' => 'date',
        'released_date' => 'date',
        'requested_at' => 'datetime',
        'consent_expires_at' => 'datetime',
        'responded_at' => 'datetime',
        'responded_by' => 'integer',
        'loan_id' => 'integer',
        'locked_at' => 'datetime',
        'released_at' => 'datetime',
        'arrears_notified_at' => 'datetime',
        'arrears_notice_count' => 'integer',
        'substitutes_id' => 'integer',
        'substituted_by_id' => 'integer',
        'release_requested_at' => 'datetime',
        'reschedule_notified_at' => 'datetime',
    ];

    public function loanApplication(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** The guarantee this one is replacing, while it waits to take over. */
    public function substitutes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'substitutes_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'guarantor_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(SavingsGroup::class, 'guarantor_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'responded_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), self::ACTIVE_STATUSES);
    }

    /** The guarantor's display name, whichever kind of guarantor this is. */
    public function guarantorName(): ?string
    {
        return $this->guarantor_type === self::TYPE_GROUP
            ? $this->group?->name
            : $this->member?->name;
    }
}
