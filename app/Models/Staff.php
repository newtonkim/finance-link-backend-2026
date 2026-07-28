<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAuthenticatedBranch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $role
 * @property int|null $role_id
 * @property int|null $branch_id
 * @property bool $is_tenant_admin
 * @property string $status
 * @property string|null $avatar
 * @property array|null $branch_can_be_accessed
 * @method static \Illuminate\Database\Eloquent\Builder|Staff query()
 * @method static \Illuminate\Database\Eloquent\Builder|Staff where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|Staff create(array $attributes = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Staff findOrFail($id, $columns = ['*'])
 * @mixin \Illuminate\Database\Eloquent\Model
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @mixin \Illuminate\Database\Query\Builder
 */
class Staff extends Authenticatable
{
    use BelongsToAuthenticatedBranch, HasApiTokens, HasFactory, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The connection name for the model.
     *
     * @var string|null
     */
    protected $connection = 'tenant';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'role_id',
        'branch_id',
        'is_tenant_admin',
        'status',
        'is_loan_officer',
        'avatar',
        'can_vote_on_loans',
        'can_manage_branch',
        'can_finalise_loan',
        'branch_can_be_accessed',
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
            'is_tenant_admin' => 'boolean',
            'is_loan_officer' => 'boolean',
            'branch_id' => 'integer',
            'can_vote_on_loans' => 'boolean',
            'can_manage_branch' => 'boolean',
            'can_finalise_loan' => 'boolean',
            'branch_can_be_accessed' => 'array',
        ];
    }

    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar
            ? asset('storage/'.$this->avatar)
            : null;
    }
}
