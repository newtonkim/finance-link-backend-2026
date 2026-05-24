<?php

namespace App\Tenant\Modules\Savings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavingsProductCharge extends Model
{
    use HasFactory;

    protected static function newFactory(): \Database\Factories\SavingsProductChargeFactory
    {
        return \Database\Factories\SavingsProductChargeFactory::new();
    }

    protected $connection = 'tenant';

    protected $fillable = [
        'savings_product_id',
        'general_charge_id',
        'name',
        'type',
        'minimum_amount',
        'maximum_amount',
        'charge_type',
        'amount',
        'is_reversible',
    ];

    protected $casts = [
        'minimum_amount' => 'decimal:2',
        'maximum_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'is_reversible' => 'boolean',
    ];

    public function savingsProduct(): BelongsTo
    {
        return $this->belongsTo(SavingsProduct::class);
    }

    public function generalCharge(): BelongsTo
    {
        return $this->belongsTo(GeneralCharge::class);
    }
}
