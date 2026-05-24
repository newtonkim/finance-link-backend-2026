<?php

namespace App\Tenant\Support;

use App\Tenant\Modules\Settings\Models\CurrencySetting;
use Williamug\MoneyFormatter\MoneyFormatter;

class TenantMoney
{
    private static ?string $currencyCode = null;

    public static function code(): string
    {
        if (self::$currencyCode !== null) {
            return self::$currencyCode;
        }

        self::$currencyCode = CurrencySetting::current()->default_currency ?? 'UGX';

        return self::$currencyCode;
    }

    public static function format(float|int|string|null $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return app(MoneyFormatter::class)->format($amount, self::code());
    }
}
