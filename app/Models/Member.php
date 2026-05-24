<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAuthenticatedBranch;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Members\Models\MemberCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Shares\Models\Share;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @method static \Illuminate\Database\Eloquent\Builder|Member query()
 * @method static \Illuminate\Database\Eloquent\Builder|Member where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|Member create(array $attributes = [])
 * @method static \Illuminate\Database\Eloquent\Builder|Member findOrFail($id, $columns = ['*'])
 * @mixin \Illuminate\Database\Eloquent\Model
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class Member extends Authenticatable
{
    use BelongsToAuthenticatedBranch, HasFactory, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    protected static function newFactory()
    {
        return \Database\Factories\MemberFactory::new();
    }

    const TYPE_FULL = 'full';

    const TYPE_ASSOCIATE = 'associate';

    const TYPE_GROUP_ONLY = 'group_only';

    /**
     * The connection name for the model.
     *
     * @var string|null
     */
    protected $connection = 'tenant';

    protected $fillable = [
        'member_number',
        'code',
        'account_number',
        'employee_number',
        'name',
        'email',
        'password',
        'salutation',
        'gender',
        'dob',
        'phone',
        'phone_country',
        'other_contact',
        'other_contact_country',
        'mobile_money_number',
        'mobile_money_country',
        'national_id_number',
        'marital_status',
        'nationality',
        'address',
        'next_of_kin',
        'next_of_kin_contact',
        'next_of_kin_contact_country',
        'initial_deposit',
        'opening_balance',
        'shares_quantity',
        'joined_at',
        'status',
        'is_shareholder',
        'profile_picture',
        'registered_by',
        'referred_by',
        'member_type',
        'branch_id',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
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
            'dob' => 'date',
            'joined_at' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'initial_deposit' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'shares_quantity' => 'integer',
            'branch_id' => 'integer',
        ];
    }

    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->profile_picture
            ? asset('storage/'.$this->profile_picture)
            : null;
    }

    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }

    public function referredBy()
    {
        return $this->belongsTo(Staff::class, 'referred_by');
    }

    public function registeredBy()
    {
        return $this->belongsTo(Staff::class, 'registered_by');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function memberCharges(): HasMany
    {
        return $this->hasMany(MemberCharge::class);
    }

    /**
     * Scope a query to only include active members.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
