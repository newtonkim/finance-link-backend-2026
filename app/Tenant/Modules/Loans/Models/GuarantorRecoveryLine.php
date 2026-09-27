<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Member;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One savings account's part in a recovery: the borrower's own, or a guarantor's.
 * A group guarantor's line takes from a group savings account instead, and records
 * how it was taken from the group's members (group_member_split), so that the
 * borrower's repayments go back to the same members.
 */
class GuarantorRecoveryLine extends Model
{
    protected $connection = 'tenant';

    const SOURCE_BORROWER = 'borrower';

    const SOURCE_GUARANTOR = 'guarantor';

    protected $fillable = [
        'guarantor_recovery_id',
        'source',
        'loan_application_guarantor_id',
        'member_id',
        'savings_account_id',
        'group_savings_account_id',
        'group_member_split',
        'amount',
        'loan_transaction_id',
        'repaid_amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'repaid_amount' => 'decimal:2',
        'group_member_split' => 'array',
    ];

    public function isGroup(): bool
    {
        return $this->group_savings_account_id !== null;
    }

    public function recovery(): BelongsTo
    {
        return $this->belongsTo(GuarantorRecovery::class, 'guarantor_recovery_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
    }

    public function guarantee(): BelongsTo
    {
        return $this->belongsTo(LoanApplicationGuarantor::class, 'loan_application_guarantor_id');
    }

    /** What the borrower still owes this guarantor. */
    public function owed(): float
    {
        return round(max(0, (float) $this->amount - (float) $this->repaid_amount), 2);
    }
}
