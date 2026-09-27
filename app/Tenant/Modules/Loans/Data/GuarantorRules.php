<?php

namespace App\Tenant\Modules\Loans\Data;

use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Services\MemebersSettingSevices\FindsettingsAction;
use App\Tenant\Settings\GuarantorSettings;

/**
 * The guarantor rules that apply to one loan application: the tenant's guarantor
 * settings, with the loan product's own min/max guarantor counts taking precedence
 * where the product sets them (a product value of 0 means "use the tenant setting").
 */
class GuarantorRules
{
    public function __construct(
        public readonly bool $required,
        public readonly int $minimum,
        /** 0 means no upper limit. */
        public readonly int $maximum,
        public readonly bool $membersOnly,
        public readonly bool $allowSelfGuarantee,
        public readonly float $exposurePercentage,
        /** 0 means coverage is not checked. */
        public readonly float $coveragePercentage,
    ) {}

    public static function for(?LoanProduct $product = null): self
    {
        $settings = new FindsettingsAction([GuarantorSettings::MODULE]);
        $defaults = collect(GuarantorSettings::definitions())
            ->mapWithKeys(fn ($d) => [$d['settings_name'] => $d['settings_action']['action']]);

        $value = fn (string $name) => $settings->settingValue($name, $defaults[$name]);
        $enabled = fn (string $name) => in_array($value($name), ['1', 'true', 1, true], true);

        $productMinimum = (int) ($product?->min_guarantors ?? 0);
        $productMaximum = (int) ($product?->max_guarantors ?? 0);

        $required = $enabled('sacco-guarantor-required-on-loan-application') || $productMinimum > 0;

        $minimum = $productMinimum > 0
            ? $productMinimum
            : ($required ? max(0, (int) $value('sacco-guarantor-minimum-number')) : 0);

        $maximum = $productMaximum > 0
            ? $productMaximum
            : max(0, (int) $value('sacco-guarantor-maximum-number'));

        return new self(
            required: $required,
            minimum: $minimum,
            maximum: $maximum,
            membersOnly: $enabled('sacco-guarantor-must-be-an-active-member'),
            allowSelfGuarantee: $enabled('sacco-guarantor-allow-self-guarantee'),
            exposurePercentage: min(100, max(0, (float) $value('sacco-guarantor-maximum-exposure-percentage'))),
            coveragePercentage: max(0, (float) $value('sacco-guarantor-required-coverage-percentage')),
        );
    }

    public function toArray(): array
    {
        return [
            'required' => $this->required,
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'members_only' => $this->membersOnly,
            'allow_self_guarantee' => $this->allowSelfGuarantee,
            'exposure_percentage' => $this->exposurePercentage,
            'coverage_percentage' => $this->coveragePercentage,
        ];
    }
}
