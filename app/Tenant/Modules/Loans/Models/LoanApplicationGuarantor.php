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

    const STATUS_PROPOSED = 'proposed';

    const STATUS_ACCEPTED = 'accepted';

    const STATUS_DECLINED = 'declined';

    const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * Statuses whose pledge still counts toward the application's guarantee and
     * against the guarantor's capacity. Until the consent step exists, a proposed
     * pledge counts the same as an accepted one.
     */
    const ACTIVE_STATUSES = [self::STATUS_PROPOSED, self::STATUS_ACCEPTED];

    protected $fillable = [
        'code',
        'loan_application_id',
        'guarantor_id',
        'guarantor_account_id',
        'guarantor_type',
        'guarantee_amount',
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
    ];

    protected $casts = [
        'loan_application_id' => 'integer',
        'guarantor_id' => 'integer',
        'guarantor_account_id' => 'integer',
        'guarantee_amount' => 'decimal:2',
        'max_guarantee_used' => 'decimal:2',
        'status_changed_at' => 'datetime',
        'accepted_date' => 'date',
        'released_date' => 'date',
    ];

    public function loanApplication(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class);
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
