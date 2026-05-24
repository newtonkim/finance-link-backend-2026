<?php

namespace App\Tenant\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;

class SaccoBranding extends Model
{
    protected $connection = 'tenant';

    protected $table = 'sacco_branding';

    protected $fillable = [
        'sacco_name',
        'tagline',
        'logo_path',
    ];

    /** Always return the single row, creating it with defaults if it does not exist. */
    public static function current(): self
    {
        return self::firstOrCreate([], [
            'sacco_name' => null,
            'tagline' => null,
            'logo_path' => null,
        ]);
    }
}
