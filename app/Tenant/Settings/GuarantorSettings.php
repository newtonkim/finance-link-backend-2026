<?php

namespace App\Tenant\Settings;

/**
 * The guarantor settings module.
 *
 * Defined in one place because two things need the same list: the seeder, which
 * gives newly provisioned tenants their settings, and the migration that backfills
 * tenants provisioned before this module existed. Keeping a second copy in either
 * would let the two drift apart silently.
 */
class GuarantorSettings
{
    /** Value of system_settings.settings_module for every row below. */
    public const MODULE = 'guarantor';

    /**
     * @return array<int, array{settings_name: string, settings_action: array, settings_setting_description: string, settings_action_description: string}>
     */
    public static function definitions(): array
    {
        return [
            [
                'settings_name' => 'sacco-guarantor-required-on-loan-application',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_action_description' => 'Guarantors required',
                'settings_setting_description' => 'When enabled, a loan application cannot be submitted until it carries at least the minimum number of guarantors set below. When disabled, guarantors are optional and a loan can proceed without any.',
            ],
            [
                'settings_name' => 'sacco-guarantor-minimum-number',
                'settings_action' => ['action' => 1, 'attr' => 'number'],
                'settings_action_description' => 'Minimum guarantors',
                'settings_setting_description' => 'The fewest guarantors a loan application must have before it can be submitted. Only applies while guarantors are required.',
            ],
            [
                'settings_name' => 'sacco-guarantor-maximum-number',
                'settings_action' => ['action' => 3, 'attr' => 'number'],
                'settings_action_description' => 'Maximum guarantors',
                'settings_setting_description' => 'The most guarantors that may be attached to a single loan application. Set this no lower than the minimum above.',
            ],
            [
                'settings_name' => 'sacco-guarantor-must-be-an-active-member',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_action_description' => 'Members only',
                'settings_setting_description' => 'When enabled, only active members of the SACCO may stand as guarantors. When disabled, a guarantor may be recorded without being a member.',
            ],
            [
                'settings_name' => 'sacco-guarantor-allow-self-guarantee',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_action_description' => 'Allow self-guarantee',
                'settings_setting_description' => 'When enabled, a borrower may stand as a guarantor on their own loan. Normally left off, since a borrower guaranteeing themselves adds no security.',
            ],
            [
                'settings_name' => 'sacco-guarantor-maximum-exposure-percentage',
                'settings_action' => ['action' => 100, 'attr' => 'number'],
                'settings_action_description' => 'Maximum exposure (%)',
                'settings_setting_description' => 'The largest share of a guarantor\'s own savings that may be committed across every loan they guarantee, as a percentage. 100 allows a guarantor to pledge their full savings balance.',
            ],
            [
                'settings_name' => 'sacco-guarantor-required-coverage-percentage',
                'settings_action' => ['action' => 0, 'attr' => 'number'],
                'settings_action_description' => 'Required coverage (%)',
                'settings_setting_description' => 'The share of the loan amount that guarantees must cover before an application can be submitted. The borrower\'s own free savings count toward it. Only applies while guarantors are required; 0 turns the coverage check off, leaving only the number of guarantors.',
            ],
        ];
    }
}
