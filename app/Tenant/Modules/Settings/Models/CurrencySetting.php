<?php

namespace App\Tenant\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class CurrencySetting extends Model
{
    protected $connection = 'tenant';

    protected $table = 'currency_settings';

    protected $fillable = [
        'default_currency',
        'enabled_currencies',
    ];

    protected $casts = [
        'enabled_currencies' => 'array',
    ];

    public static function current(): self
    {
        return self::firstOrCreate([], [
            'default_currency' => 'UGX',
            'enabled_currencies' => ['UGX'],
        ]);
    }
}
