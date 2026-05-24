<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Contracts\SavingsCoaResolverInterface;
use App\Tenant\Modules\Accounting\GlCodes;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

class SavingsCoaResolver implements SavingsCoaResolverInterface
{
    public function resolvePaymentModeAccount(string $mode): ChartOfAccount
    {
        $glCode = match (strtolower(trim($mode))) {
            'cash', 'petty_cash' => GlCodes::PETTY_CASH,
            'mobile_money', 'mtn', 'mobile_money_mtn' => GlCodes::MOBILE_MONEY_MTN,
            'airtel', 'mobile_money_airtel' => GlCodes::MOBILE_MONEY_AIRTEL,
            default => GlCodes::BANK_OPERATING,
        };

        return $this->resolveByGlCode($glCode);
    }

    public function resolveSavingsLiabilityAccount(SavingsAccount $account): ChartOfAccount
    {
        $accountType = strtolower($account->account_type ?? '');
        $productType = strtolower($account->savingsProduct?->type ?? '');
        $type = $accountType ?: $productType;

        $glCode = match (true) {
            str_contains($type, 'mandatory') => GlCodes::SAVINGS_MANDATORY,
            str_contains($type, 'fixed') => GlCodes::SAVINGS_FIXED_DEPOSIT,
            default => GlCodes::SAVINGS_VOLUNTARY,
        };

        return $this->resolveByGlCode($glCode);
    }

    public function resolveByGlCode(string $glCode): ChartOfAccount
    {
        $account = ChartOfAccount::where('gl_code', $glCode)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            throw new \RuntimeException(
                "COA entry missing for GL code {$glCode}. Run the Chart of Accounts seeder."
            );
        }

        return $account;
    }
}
