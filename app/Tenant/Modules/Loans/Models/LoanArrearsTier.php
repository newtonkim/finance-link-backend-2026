<?php

namespace App\Tenant\Modules\Loans\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanArrearsTier extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    protected $table = 'loan_arrears_tiers';

    protected $fillable = [
        'from_day',
        'to_day',
        'charge_type',
        'charge_value',
        'applies_to',
        'is_active',
    ];

    protected $casts = [
        'from_day' => 'integer',
        'to_day' => 'integer',
        'charge_value' => 'float',
        'is_active' => 'boolean',
    ];
}
