<?php

namespace App\Tenant\Modules\Loans\Models;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class LoanAppliedCharge extends Model
{
    protected $connection = 'tenant';

    protected $table = 'loan_applied_charges';

    protected static array $resolvedAppliedChargeTableByConnection = [];

    protected $fillable = [
        'loan_id',
        'charge_id',
        'name',
        'charge_type',
        'application_timing',
        'charge_amount',
        'default_amount',
        'used_amount',
        'is_waived',
        'waived_by',
        'waived_date',
        'waiver_reason',
        'is_mandatory',
    ];

    protected $casts = [
        'charge_amount' => 'decimal:2',
        'default_amount' => 'decimal:2',
        'used_amount' => 'decimal:2',
        'is_waived' => 'boolean',
        'is_mandatory' => 'boolean',
        'waived_date' => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'waived_by');
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0, (float) $this->charge_amount - (float) $this->used_amount);
    }

    public function getTable()
    {
        $connection = $this->getConnectionName() ?? $this->connection;

        if (! isset(self::$resolvedAppliedChargeTableByConnection[$connection])) {
            if (Schema::connection($connection)->hasTable('loan_applied_charges')) {
                self::$resolvedAppliedChargeTableByConnection[$connection] = 'loan_applied_charges';
            } elseif (
                Schema::connection($connection)->hasTable('loan_charges')
                && Schema::connection($connection)->hasColumn('loan_charges', 'loan_id')
                && Schema::connection($connection)->hasColumn('loan_charges', 'charge_id')
            ) {
                // Legacy schema: applied charges were stored in loan_charges.
                self::$resolvedAppliedChargeTableByConnection[$connection] = 'loan_charges';
            } else {
                self::$resolvedAppliedChargeTableByConnection[$connection] = $this->table;
            }
        }

        return self::$resolvedAppliedChargeTableByConnection[$connection];
    }
}
