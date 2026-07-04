<?php

namespace App\Tenant\Modules\Transactions\Models;

use App\Models\Member;
use App\Models\Staff;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        // Stamp branch_id on INSERT only — no read scope (Transaction is actor-owned).
        static::creating(function ($model) {
            if (empty($model->branch_id)) {
                $branchId = BranchContext::actingBranchId();
                if ($branchId !== null) {
                    $model->branch_id = $branchId;
                }
            }
        });
    }

    protected $connection = 'tenant';

    protected $fillable = [
        'reference',
        'receipt_number',
        'member_id',
        'type',
        'amount',
        'charge_amount',
        'group_savings_account_id',
        'group_member_account_balance_before_transaction',
        'amount_before_transactions',
        'deposited_amount_before_charge',
        'payment_mode',
        'deposited_by',
        'transaction_date',
        'account_id',
        'account_type',
        'narration',
        'charge_name',
        'gl_credit_account_id',
        'is_reversible',
        'is_migrated',
        'is_reversed',
        'reversal_of',
        'grouped_with',
        'created_by',
        'branch_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'charge_amount' => 'decimal:2',
        'amount_before_transactions' => 'decimal:2',
        'transaction_date' => 'date',
        'is_migrated' => 'boolean',
        'is_reversed' => 'boolean',
        'is_reversible' => 'boolean',
        'branch_id' => 'integer',
        'gl_credit_account_id' => 'integer',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    /**
     * Get the polymorphic account (savings_account or loan).
     */
    public function account(): MorphTo
    {
        return $this->morphTo();
    }
}
