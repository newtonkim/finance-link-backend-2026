<?php

namespace App\Tenant\Modules\Loans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoanApplicationCollateral extends Model
{
    protected $connection = 'tenant';

    protected $fillable = [
        'loan_application_id',
        'asset_type',
        'description',
        'estimated_value',
        'notes',
        'proof_path',
    ];

    protected $casts = [
        'estimated_value' => 'decimal:2',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }
}
