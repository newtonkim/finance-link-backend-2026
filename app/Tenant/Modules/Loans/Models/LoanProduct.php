<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    protected $fillable = [
        'code',
        'name',
        'description',
        'min_amount',
        'max_amount',
        'exposure_limit',
        'arrears_action',
        'interest_rate',
        'interest_method',
        'repayment_structure',
        'interest_period',
        'loan_duration',
        'duration_type',
        'repayment_cycle',
        'min_guarantors',
        'max_guarantors',
        'min_membership_months',
        'grace_period',
        'penalty_grace_days',
        'savings_appraisal_threshold',
        'warning_days',
        'max_securities',
        'security_value_percentage',
        'allow_sub_schedule',
        'penalty_rate',
        'penalty_type',
        'requires_approval',
        'allow_top_up',
        'allow_reschedule',
        'processing_fee_type',
        'processing_fee_value',
        'loan_portfolio_account_id',
        'interest_income_account_id',
        'interest_receivable_account_id',
        'penalty_income_account_id',
        'penalty_receivable_account_id',
        'disbursement_account_id',
        'charges_income_account_id',
        'charges_receivable_account_id',
        'created_by',
        'updated_by',
        'is_active',
        'topup_auto_disbursement',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'exposure_limit' => 'decimal:2',
        'min_guarantors' => 'integer',
        'max_guarantors' => 'integer',
        'min_membership_months' => 'integer',
        'interest_rate' => 'decimal:2',
        'penalty_rate' => 'decimal:2',
        'loan_duration' => 'integer',
        'grace_period' => 'integer',
        'penalty_grace_days' => 'integer',
        'savings_appraisal_threshold' => 'decimal:2',
        'warning_days' => 'integer',
        'max_securities' => 'integer',
        'security_value_percentage' => 'decimal:2',
        'allow_sub_schedule' => 'boolean',
        'processing_fee_value' => 'decimal:2',
        'loan_portfolio_account_id' => 'integer',
        'interest_income_account_id' => 'integer',
        'interest_receivable_account_id' => 'integer',
        'penalty_income_account_id' => 'integer',
        'penalty_receivable_account_id' => 'integer',
        'disbursement_account_id' => 'integer',
        'charges_income_account_id' => 'integer',
        'charges_receivable_account_id' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'requires_approval' => 'boolean',
        'allow_top_up' => 'boolean',
        'allow_reschedule' => 'boolean',
        'is_active' => 'boolean',
        'topup_auto_disbursement' => 'boolean',
    ];

    public function approvalSetting(): HasOne
    {
        return $this->hasOne(LoanApprovalSetting::class);
    }

    public function penaltyRules(): HasMany
    {
        return $this->hasMany(LoanPenaltyRule::class);
    }

    public function requiredDocuments(): HasMany
    {
        return $this->hasMany(LoanProductRequiredDocument::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function charges(): BelongsToMany
    {
        return $this->belongsToMany(LoanCharge::class, 'loan_product_charge', 'loan_product_id', 'loan_charge_id')
            ->withTimestamps();
    }

    public function portfolioAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'loan_portfolio_account_id');
    }

    public function interestIncomeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'interest_income_account_id');
    }

    public function interestReceivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'interest_receivable_account_id');
    }

    public function penaltyIncomeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'penalty_income_account_id');
    }

    public function penaltyReceivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'penalty_receivable_account_id');
    }

    public function disbursementAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'disbursement_account_id');
    }

    public function chargesIncomeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'charges_income_account_id');
    }

    public function chargesReceivableAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'charges_receivable_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'updated_by');
    }
}
