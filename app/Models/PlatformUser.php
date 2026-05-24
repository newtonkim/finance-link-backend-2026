<?php

namespace App\Models;

use App\Concerns\HasDynamicConnection;
use Database\Factories\PlatformUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class PlatformUser extends Authenticatable
{
    use HasApiTokens, HasDynamicConnection, HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected static function newFactory()
    {
        return PlatformUserFactory::new();
    }

    /**
     * The connection name for the model.
     *
     * @var string|null
     */
    protected $connection = 'master';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
