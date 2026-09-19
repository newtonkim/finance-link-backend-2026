<?php

namespace App\Models;

use App\Concerns\HasDynamicConnection;
use Database\Factories\PlatformUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
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
        'avatar',
        'password',
    ];

    /**
     * avatar holds a bare path on the 'public' disk. Appending a resolved URL means
     * every consumer that serialises this model — /me, and anything hydrating the
     * frontend auth store — gets something the browser can actually load, instead
     * of a relative path that resolves against whatever page is open.
     *
     * @var list<string>
     */
    protected $appends = ['avatar_url'];

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

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar ? Storage::disk('public')->url($this->avatar) : null;
    }
}
