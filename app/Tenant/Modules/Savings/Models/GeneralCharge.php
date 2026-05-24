<?php

namespace App\Tenant\Modules\Savings\Models;

use App\Models\Concerns\BelongsToAuthenticatedBranch;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Database\Factories\GeneralChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneralCharge extends Model
{
    use BelongsToAuthenticatedBranch, HasFactory;

    protected static function newFactory(): GeneralChargeFactory
    {
        return GeneralChargeFactory::new();
    }

    protected $connection = 'tenant';

    protected $table = 'general_charges';

    protected $fillable = [
        'name',
        'is_revenue',
        'application',
        'where_to_apply',
        'is_fine',
        'charge_type',
        'amount',
        'interval_type',
        'interval',
        'credit_account_id',
        'is_reversible',
        'is_active',
        'branch_id',
    ];

    protected $casts = [
        'is_revenue'    => 'boolean',
        'is_fine'       => 'boolean',
        'is_reversible' => 'boolean',
        'amount'        => 'decimal:2',
        'is_active'     => 'boolean',
        'branch_id'     => 'integer',
    ];

    /**
     * The income Chart of Account credited when this charge is collected.
     */
    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }

    /**
     * Pivot rows linking this charge to savings products. Canonical source of
     * truth for "which savings products fire this charge, and on which events".
     * Each row pins a (savings_product_id, type=deposit|withdraw|transfer) pair.
     */
    public function productCharges(): HasMany
    {
        return $this->hasMany(SavingsProductCharge::class, 'general_charge_id');
    }
}
