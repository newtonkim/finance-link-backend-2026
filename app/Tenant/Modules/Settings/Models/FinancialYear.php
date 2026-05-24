<?php

namespace App\Tenant\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialYear extends Model
{
    protected $connection = 'tenant';

    protected $table = 'financial_years';

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];
}
