<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Concerns\BelongsToAuthenticatedBranch;
use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @method static \Illuminate\Database\Eloquent\Builder|Loan query()
 * @method static \Illuminate\Database\Eloquent\Builder|Loan where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|Loan create(array $attributes = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Loan findOrFail($id, $columns = ['*'])
 * @method bool save(array $options = [])
 * @method bool delete()
 * @method static \Illuminate\Database\Eloquent\Builder|Loan with($relations)
 * @method static \Illuminate\Database\Eloquent\Builder|Loan orderBy($column, $direction = 'asc')
 * @method static \Illuminate\Database\Eloquent\Collection|Loan[] get($columns = ['*'])
 * @method static \Illuminate\Database\Eloquent\Collection|Loan[] all($columns = ['*'])
 * @method static Loan|null first($columns = ['*'])
 * @method static Loan find($id, $columns = ['*'])
 * @mixin \Illuminate\Database\Eloquent\Model
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @mixin \Illuminate\Database\Query\Builder
 */
class Loan extends Model
{
    use BelongsToAuthenticatedBranch, SoftDeletes;

    protected $connection = 'tenant';

    protected $fillable = [
        'loan_no',
        'loan_application_id',
        'member_id',
        'loan_product_id',
        'principal',
        'processing_fee',
        'total_charges_deducted',
        'net_disbursed_amount',
        'interest_rate',
        'term_months',
        'disbursed_at',
        'disbursement_method',
        'disbursement_reference',
        'charge_deduction_mode',
        'charge_receipt_no',
        'savings_account_id',
        'mobile_money_provider',
        'mobile_money_number',
        'status',
        'outstanding_balance',
        'approved_by',
        'loan_officer_id',
        'disbursed_by',
        'branch_id',
        'notes',
        'is_rescheduled',
        'reschedule_count',
        'original_term_months',
        'original_interest_rate',
        'schedule_date',
        'parent_loan_id',
        'topup_type',
    ];

    protected $casts = [
        'principal' => 'decimal:2',
        'processing_fee' => 'decimal:2',
        'total_charges_deducted' => 'decimal:2',
        'net_disbursed_amount' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'outstanding_balance' => 'decimal:2',
        'disbursed_at' => 'date',
        'schedule_date' => 'date',
        'branch_id' => 'integer',
        'loan_application_id' => 'integer',
        'loan_officer_id' => 'integer',
        'disbursed_by' => 'integer',
        'savings_account_id' => 'integer',
        'status' => LoanStatus::class,
        'is_rescheduled' => 'boolean',
        'reschedule_count' => 'integer',
        'original_term_months' => 'integer',
        'original_interest_rate' => 'decimal:2',
        'parent_loan_id' => 'integer',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function loanApplication(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'approved_by');
    }

    public function loanOfficer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'loan_officer_id');
    }

    public function disbursedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'disbursed_by');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(LoanSchedule::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanTransaction::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(LoanAppliedCharge::class);
    }

    /**
     * Alias for charges() — used by controller eager-loads and the application resource.
     */
    public function appliedCharges(): HasMany
    {
        return $this->hasMany(LoanAppliedCharge::class);
    }

    public function savingsAccount(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class);
    }

    public function parentLoan(): BelongsTo
    {
        return $this->belongsTo(Loan::class, 'parent_loan_id');
    }

    /**
     * The earliest unpaid / partially-paid installment.
     */
    public function nextSchedule(): HasOne
    {
        return $this->hasOne(LoanSchedule::class)
            ->whereIn('status', ['pending', 'partial', 'arrears'])
            ->orderBy('due_date');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(LoanStatusHistory::class);
    }

    public function reschedules(): HasMany
    {
        return $this->hasMany(LoanReschedule::class, 'original_loan_id');
    }

    public function latestReschedule(): HasOne
    {
        return $this->hasOne(LoanReschedule::class, 'original_loan_id')
            ->latestOfMany('reschedule_date');
    }

    /**
     * Loans created from topping up this loan.
     */
    public function childLoans(): HasMany
    {
        return $this->hasMany(self::class, 'parent_loan_id');
    }

    /**
     * Calculate total outstanding balance (Principal + Interest + Charges + Penalties)
     * from unpaid/partially paid schedules.
     */
    public function getTotalOutstandingAmount(): float
    {
        return (float) (LoanSchedule::query()
            ->where('loan_id', $this->id)
            ->where('status', '!=', 'paid')
            ->selectRaw('COALESCE(SUM(
                (COALESCE(principal_due, 0) + COALESCE(interest_due, 0) + COALESCE(charges_due, 0) + COALESCE(penalty_due, 0))
                -
                (COALESCE(principal_paid, 0) + COALESCE(interest_paid, 0) + COALESCE(charges_paid, 0) + COALESCE(penalty_paid, 0))
            ), 0) as outstanding_total')
            ->value('outstanding_total'));
    }
}
