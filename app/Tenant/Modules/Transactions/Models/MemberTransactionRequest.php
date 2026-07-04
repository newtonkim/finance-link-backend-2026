<?php

namespace App\Tenant\Modules\Transactions\Models;

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MemberTransactionRequest extends Model
{
    use SoftDeletes;

    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_WITHDRAWAL = 'withdrawal';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $connection = 'tenant';

    protected $fillable = [
        'member_id',
        'savings_account_id',
        'type',
        'amount',
        'payment_mode',
        'narration',
        'requested_date',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'linked_transaction_id',
        'receipt_number',
        'transaction_reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'requested_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reviewed_by');
    }

    public function linkedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'linked_transaction_id');
    }
}
