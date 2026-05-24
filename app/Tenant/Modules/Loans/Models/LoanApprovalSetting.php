<?php

namespace App\Tenant\Modules\Loans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanApprovalSetting extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'loan_product_id',
        'quorum_size',
        'approval_threshold',
        'amount_tiers',
        'abstention_timeout_hours',
    ];

    protected $casts = [
        'loan_product_id' => 'integer',
        'quorum_size' => 'integer',
        'approval_threshold' => 'integer',
        'amount_tiers' => 'array',
        'abstention_timeout_hours' => 'integer',
    ];

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    /**
     * Resolve quorum size and approval threshold for a given amount.
     */
    public function resolveForAmount(float $amount): array
    {
        $tiers = $this->amount_tiers ?? [];

        foreach ($tiers as $tier) {
            $min = $tier['min'] ?? 0;
            $max = $tier['max'] ?? null;

            if ($amount >= $min && ($max === null || $amount <= $max)) {
                return [
                    'quorum_size' => $tier['quorum_size'] ?? $this->quorum_size,
                    'approval_threshold' => $tier['approval_threshold'] ?? $this->approval_threshold,
                    'unanimity_required' => $tier['unanimity'] ?? false,
                ];
            }
        }

        return [
            'quorum_size' => $this->quorum_size,
            'approval_threshold' => $this->approval_threshold,
            'unanimity_required' => false,
        ];
    }
}
