<?php

namespace App\Tenant\Settings;

use Illuminate\Support\Facades\DB;

/**
 * The guarantor settings module.
 *
 * Defined in one place because several things need the same list: the seeder, which
 * gives newly provisioned tenants their settings, the migration that backfills
 * tenants provisioned before this module existed, and the settings page, which
 * restores any that are still missing. Keeping a second copy anywhere would let
 * them drift apart silently.
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
            [
                'settings_name' => 'sacco-guarantor-consent-required',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_action_description' => 'Guarantor must accept',
                'settings_setting_description' => 'When enabled, each guarantor is asked to accept or decline, and only guarantees they have accepted count toward the minimum number and coverage. An application whose guarantors have not all answered waits in "Awaiting guarantors" and moves on by itself once enough accept. When disabled, a guarantee counts as soon as it is recorded.',
            ],
            [
                'settings_name' => 'sacco-guarantor-consent-expiry-days',
                'settings_action' => ['action' => 7, 'attr' => 'number'],
                'settings_action_description' => 'Days to respond',
                'settings_setting_description' => 'How many days a guarantor has to accept or decline before the request expires. An expired request no longer holds the guarantor\'s savings and can be sent again.',
            ],
            [
                'settings_name' => 'sacco-guarantor-hold-savings',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_action_description' => 'Hold guarantors\' savings',
                'settings_setting_description' => 'When enabled, a guarantor cannot withdraw, transfer to someone else, or close an account if it would take their savings below what they have guaranteed. The hold starts once the guarantee is binding (accepted, or recorded while guarantors do not have to accept) and is released when the loan is closed, or when the application is rejected or cancelled.',
            ],
            [
                'settings_name' => 'sacco-guarantor-arrears-notice-days',
                'settings_action' => ['action' => 30, 'attr' => 'number'],
                'settings_action_description' => 'Warn guarantors after (days overdue)',
                'settings_setting_description' => 'When a loan has been overdue this many days, its guarantors are sent an SMS saying how far behind it is and how much of their savings is held for it. Sent once for each spell of arrears. 0 turns the warning off.',
            ],
            [
                'settings_name' => 'sacco-guarantor-arrears-reminder-days',
                'settings_action' => ['action' => 0, 'attr' => 'number'],
                'settings_action_description' => 'Repeat the warning every (days)',
                'settings_setting_description' => 'While the loan stays overdue, warn its guarantors again this many days after the last warning. 0 sends the warning only once.',
            ],
            [
                'settings_name' => 'sacco-guarantor-recovery-after-days',
                'settings_action' => ['action' => 90, 'attr' => 'number'],
                'settings_action_description' => 'Recover from guarantors after (days overdue)',
                'settings_setting_description' => 'How long a loan must be overdue before staff can recover it from the borrower\'s savings and then its guarantors\' savings. A recovery is proposed by one staff member and approved by another. 0 allows it as soon as the loan is overdue.',
            ],
            [
                'settings_name' => 'sacco-guarantor-recovery-loan-term-months',
                'settings_action' => ['action' => 12, 'attr' => 'number'],
                'settings_action_description' => 'Months for the borrower to repay guarantors',
                'settings_setting_description' => 'What guarantors pay is turned into a recovery loan the borrower owes them, repaid in equal monthly instalments over this many months. Each repayment goes back into the guarantors\' savings.',
            ],
            [
                'settings_name' => 'sacco-guarantor-topup-needs-guarantors',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_action_description' => 'Guarantors must agree to top-ups',
                'settings_setting_description' => 'When enabled, topping up a loan that has guarantors always goes through a loan application instead of being paid out straight away, and the old loan\'s guarantors are carried onto it so they can agree to the new amount (or be replaced). When disabled, a product set to pay top-ups out straight away does so, and the new loan has no guarantors.',
            ],
        ];
    }

    /**
     * Give the tenant any guarantor setting it does not have yet, at its default.
     * Settings already there keep their values. A row under the right name but in
     * another module is moved into this one, so it shows on the settings page.
     *
     * system_settings has no unique index on settings_name, so each row is checked
     * by name rather than relying on insertOrIgnore.
     *
     * @return int how many settings were added or moved
     */
    public static function restoreMissing(): int
    {
        $existing = DB::table('system_settings')
            ->whereIn('settings_name', array_column(self::definitions(), 'settings_name'))
            ->pluck('settings_module', 'settings_name');

        $restored = 0;

        foreach (self::definitions() as $definition) {
            $name = $definition['settings_name'];

            if ($existing->has($name)) {
                if ($existing[$name] !== self::MODULE) {
                    DB::table('system_settings')->where('settings_name', $name)->update(['settings_module' => self::MODULE]);
                    $restored++;
                }

                continue;
            }

            DB::table('system_settings')->insert([
                'settings_name' => $name,
                'settings_module' => self::MODULE,
                'settings_status' => 'active',
                'settings_action' => json_encode($definition['settings_action']),
                'settings_action_description' => $definition['settings_action_description'],
                'settings_setting_description' => $definition['settings_setting_description'],
                'system_type' => 'system',
                // created_by and updated_by are NOT NULL with no default. Existing
                // system-seeded rows carry 0, so these match rather than inventing a
                // staff id for rows nobody created.
                'created_by' => 0,
                'updated_by' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $restored++;
        }

        return $restored;
    }
}
