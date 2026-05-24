<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Settings\Models\CurrencySetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CurrencySettingsController extends Controller
{
    private const CURRENCIES = [
        ['code' => 'UGX', 'name' => 'Ugandan Shilling', 'symbol' => 'UGX'],
        ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$'],
        ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€'],
        ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£'],
        ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$'],
        ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$'],
        ['code' => 'NZD', 'name' => 'New Zealand Dollar', 'symbol' => 'NZ$'],
        ['code' => 'CHF', 'name' => 'Swiss Franc', 'symbol' => 'CHF'],
        ['code' => 'SEK', 'name' => 'Swedish Krona', 'symbol' => 'SEK'],
        ['code' => 'NOK', 'name' => 'Norwegian Krone', 'symbol' => 'NOK'],
        ['code' => 'DKK', 'name' => 'Danish Krone', 'symbol' => 'DKK'],
        ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$'],
        ['code' => 'HKD', 'name' => 'Hong Kong Dollar', 'symbol' => 'HK$'],
        ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥'],
        ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥'],
        ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹'],
        ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'AED'],
        ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SAR'],
        ['code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh'],
        ['code' => 'TZS', 'name' => 'Tanzanian Shilling', 'symbol' => 'TSh'],
        ['code' => 'RWF', 'name' => 'Rwandan Franc', 'symbol' => 'FRw'],
        ['code' => 'NGN', 'name' => 'Nigerian Naira', 'symbol' => '₦'],
        ['code' => 'GHS', 'name' => 'Ghanaian Cedi', 'symbol' => '₵'],
        ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R'],
        ['code' => 'ETB', 'name' => 'Ethiopian Birr', 'symbol' => 'Br'],
        ['code' => 'XOF', 'name' => 'West African CFA Franc', 'symbol' => 'CFA'],
        ['code' => 'XAF', 'name' => 'Central African CFA Franc', 'symbol' => 'CFA'],
        ['code' => 'MAD', 'name' => 'Moroccan Dirham', 'symbol' => 'MAD'],
        ['code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => 'E£'],
        ['code' => 'BWP', 'name' => 'Botswana Pula', 'symbol' => 'P'],
        ['code' => 'ZMW', 'name' => 'Zambian Kwacha', 'symbol' => 'ZK'],
        ['code' => 'MUR', 'name' => 'Mauritian Rupee', 'symbol' => '₨'],
    ];

    public function currencies()
    {
        return response()->json([
            'data' => self::CURRENCIES,
        ]);
    }

    public function show()
    {
        return response()->json([
            'data' => CurrencySetting::current(),
        ]);
    }

    public function update(Request $request)
    {
        $allowedCodes = array_column(self::CURRENCIES, 'code');

        $validated = $request->validate([
            'default_currency' => ['required', 'string', Rule::in($allowedCodes)],
            'enabled_currencies' => ['required', 'array', 'min:1'],
            'enabled_currencies.*' => ['string', Rule::in($allowedCodes)],
        ]);

        $enabled = collect($validated['enabled_currencies'])
            ->push($validated['default_currency'])
            ->unique()
            ->values()
            ->all();

        $settings = CurrencySetting::current();
        $settings->fill([
            'default_currency' => $validated['default_currency'],
            'enabled_currencies' => $enabled,
        ])->save();

        return response()->json([
            'message' => 'Currency settings saved successfully.',
            'data' => $settings,
        ]);
    }
}
