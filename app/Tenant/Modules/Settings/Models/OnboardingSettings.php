<?php

namespace App\Tenant\Modules\Settings\Models;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingSettings extends Model
{
    protected $connection = 'tenant';

    protected $table = 'onboarding_settings';

    protected $fillable = [
        'shares_compulsory',
        'min_shares_on_onboarding',
        'share_price',
        'share_payment_account_id',
        'shares_compulsory_applies_to_existing',
        'auto_create_savings_account',
        'require_member_approval',
        'loyal_member_min_tenure_months',
        'hide_initial_deposit_field',
        'hide_opening_balance_field',
        'hide_is_shareholder_field',
        'reversal_requires_approval',
        'reversal_approver_roles',
        'reversal_max_days',
        'regular_savings_interest_enabled',
    ];

    protected $casts = [
        'shares_compulsory' => 'boolean',
        'min_shares_on_onboarding' => 'integer',
        'share_price' => 'decimal:2',
        'share_payment_account_id' => 'integer',
        'shares_compulsory_applies_to_existing' => 'boolean',
        'auto_create_savings_account' => 'boolean',
        'require_member_approval' => 'boolean',
        'loyal_member_min_tenure_months' => 'integer',
        'hide_initial_deposit_field' => 'boolean',
        'hide_opening_balance_field' => 'boolean',
        'hide_is_shareholder_field' => 'boolean',
        'reversal_requires_approval' => 'boolean',
        'reversal_approver_roles' => 'array',
        'reversal_max_days' => 'integer',
        'regular_savings_interest_enabled' => 'boolean',
    ];

    /** Always return the single row, creating it with defaults if it does not exist. */
    public static function current(): self
    {
        return self::firstOrCreate([], [
            'shares_compulsory' => false,
            'min_shares_on_onboarding' => 1,
            'share_price' => 0.00,
            'share_payment_account_id' => null,
            'shares_compulsory_applies_to_existing' => false,
            'auto_create_savings_account' => true,
            'require_member_approval' => false,
            'loyal_member_min_tenure_months' => 12,
            'hide_initial_deposit_field' => false,
            'hide_opening_balance_field' => false,
            'hide_is_shareholder_field' => false,
            'reversal_requires_approval' => false,
            'reversal_approver_roles' => [],
            'reversal_max_days' => 0,
            'regular_savings_interest_enabled' => false,
        ]);
    }

    public function sharePaymentAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'share_payment_account_id');
    }
}
