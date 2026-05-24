<?php

namespace Database\Seeders;

use App\Http\Globals\GlobalHelpers;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


function codeCustomeGenerator($module = "members-onboarding", $settings_name = null)
{
    return  [
        'settings_name' => $settings_name,
        'settings_status' => 'active',
        'settings_module' => $module,
        'settings_action' => [
            'action' => 0,
            'attr' => 'switch',
            "children-fields" => [
                [
                    'type' => 'multiselect',
                    "options" => [
                        ["id" => "{{code}}", "name" => "Code"],
                        ["id" => "{{auto-increment}}", "name" => "Auto-Incremented number"],
                        ["id" => "{{random}}", "name" => "Random Number"],
                        ["id" => "{{year}}", "name" => "Year"],
                        ["id" => "{{month}}", "name" => "Month"],
                        ["id" => "{{day}}", "name" => "Day"],
                        // ["id" => "{{auto-generate}}", "name" => "Auto Generate number"],
                        ["id" => "{{hour}}", "name" => "Hour"],
                        ["id" => "{{minute}}", "name" => "Minute"],
                        ["id" => "{{second}}", "name" => "Second"],
                    ],
                    "action" => "{{code}}-{{year}}/{{month}}/{{auto-increment}}",
                    "name" => "field_for_code_generation",
                    "placeholder" => "Select field for code generation",
                    "label" => "custom field for code generation pattern"
                ]

            ],
        ]
    ];
}

class SaccoSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = DB::connection('master')->table('tenants')->get(['database_name']);
        $settings = [
            [
                'settings_name' => 'system-default-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'CODE-%s', 'attr' => 'checkbox'],
                'settings_module' => 'all-system',
            ],
            [
                'settings_name' => 'system-max-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => '5', 'attr' => 'number'],
                'settings_module' => 'all-system',
            ],
            [
                'settings_name' => 'sacco-on-create-member-nin-mandatory',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],

                'settings_module' => 'members-onboarding',
                'settings_action_description' => 'Nin mandatory field',
                'settings_setting_description' => 'Nin mandatory field',
            ],
            [
                'settings_name' => 'sacco-on-create-member-next-of-kin-nin-mandatory',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'members-onboarding',
                'settings_action_description' => 'Next of kin mandatory field',
                'settings_setting_description' => 'Next of kin mandatory field',
            ],
            [
                'settings_name' => 'sacco-on-create-member-address-mandatory',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'members-onboarding',
                'settings_action_description' => 'Next of kin mandatory field',
                'settings_setting_description' => 'Next of kin mandatory field',
            ],
            [
                'settings_name' => 'sacco-members-member-next-of-kin-contact-mandatory',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],

                'settings_module' => 'members-onboarding',
                'settings_action_description' => 'Next of kin contact mandatory field',
                'settings_setting_description' => 'Next of kin contact mandatory field',
            ],
            [
                'settings_name' => 'sacco-members-code-str-pad',
                'settings_status' => 'active',
                'settings_action' => ['action' => '8', 'attr' => 'number'],
                'settings_module' => 'members-onboarding',
                'settings_action_description' => 'Number of digits to pad member codes with zeros. codeprefix-0000005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.MBRC-00000090',
            ],
            [
                'settings_name' => 'sacco-members-on-member-creation-save-a-sacco-account-at-the-same-time',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'members-onboarding',
                'settings_action_description' => 'Enable saving a member and creating their SACCO savings account at the same time',
                'settings_setting_description' => 'When enabled, the system will automatically create a savings account for the member during member registration. When disabled, the account must be created separately.',
            ],

            [
                'settings_name' => 'sacco-members-Require-approval-before-members-becomes-active',
                'settings_status' => 'active',
                'settings_action' => ['action' => '0', 'attr' => 'switch'],
                'settings_module' => 'members-onboarding',
                'settings_action_description' => 'Newly registered members are placed in Pending status until approved by staff',
                'settings_setting_description' => 'Members are automatically set to Active on registration and can transact immediately.',
            ],
            [
                'settings_name' => 'sacco-members-code-auto-generate',
                'settings_status' => 'active',
                'settings_action' => ['action' => '1', 'attr' => 'switch'],
                'settings_module' => 'members-onboarding',
            ],
            [
                'settings_name' => 'sacco-members-code-prefix',
                'settings_status' => 'active',
                'settings_module' => 'members-onboarding',
                'settings_action' => ['action' => 'MBRC-', 'attr' => 'text'],
            ],
            [
                'settings_name' => 'sacco-members-code-segment-length',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'number'],
                'settings_module' => 'members-onboarding',
            ],
            [
                'settings_name' => 'sacco-members-free-input-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],

                'settings_module' => 'members-onboarding',
            ],
            codeCustomeGenerator('members-onboarding', 'sacco-members-code-custom-generator'),
            [
                'settings_name' => 'sacco-members-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'members-onboarding',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],
            [
                'settings_name' => 'sacco-members-show-initial-deposit-field',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'true', 'attr' => 'switch'],
                'settings_module' => 'members-onboarding',
                'settings_action_description' => ' Hide Initial Deposit Field ',
                'settings_setting_description' => 'Hides the initial deposit field on the member registration form for both new and existing members.',

            ],
            [
                'settings_name' => 'sacco-members-minimum-tenure-months',
                'settings_status' => 'active',
                'settings_action' => ['action' => '0', 'attr' => 'number'],
                'settings_module' => 'members-onboarding',
                'settings_action_description' => 'Minimum Tenure Months',
                'settings_setting_description' => 'Minimum Tenure Monthssettings-roles-list',

            ],
            // ////
            codeCustomeGenerator('loan', 'sacco-loan-code-custom-generator'),

            [
                'settings_name' => 'sacco-loan-code-str-pad',
                'settings_status' => 'active',
                'settings_action' => ['action' => '7', 'attr' => 'text'],

                'settings_module' => 'loans',
                'settings_action_description' => 'Number of digits to pad member codes with zeros. codeprefix-0000005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.MBRC-00000090',
            ],
            [
                'settings_name' => 'sacco-loan-code-auto-generate',
                'settings_status' => 'active',
                'settings_action' => ['action' => '0', 'attr' => 'switch'],

                'settings_module' => 'loans',
            ],

            [
                'settings_name' => 'sacco-loan-code-prefix',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'SLC-', 'attr' => 'text'],
                'settings_module' => 'loans',
            ],
            [
                'settings_name' => 'sacco-loan-code-segment-length',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'number'],
                'settings_module' => 'loans',
            ],
            [
                'settings_name' => 'sacco-loan-free-input-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'loans',
            ],
            [
                'settings_name' => 'sacco-loan-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'loans',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],
            // ///////////////
            codeCustomeGenerator('savings-group', 'sacco-savings-group-code-custom-generator'),

            [
                'settings_name' => 'sacco-savings-group-dont-withdrawal-beyond-guarantee-total-amount',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'savings-group',
                'settings_action_description' => ' dont withdrawal beyond guarantee total amount ',
                'settings_setting_description' => 'dont withdrawal beyond guarantee total amount',
            ],
            [
                'settings_name' => 'sacco-savings-group-code-str-pad',
                'settings_status' => 'active',
                'settings_action' => ['action' => 7, 'attr' => 'number'],
                'settings_module' => 'savings-group',
                'settings_action_description' => 'Number of digits to pad member codes with zeros. codeprefix-0000005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.MBRC-00000090',
            ],
            [
                'settings_name' => 'sacco-savings-group-can-member-be-in-multiple-groups',
                'settings_status' => 'active',
                'settings_action' => ['action' => false, 'attr' => 'switch'],
                'settings_module' => 'savings-group',
                'settings_action_description' => 'group savings member can be in multiple groups',
                'settings_setting_description' => 'group savings member can be in multiple groups',
            ],
            [
                'settings_name' => 'sacco-savings-group-code-auto-generate',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'savings-group',
            ],

            [
                'settings_name' => 'sacco-savings-group-code-prefix',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'SSGC-', 'attr' => 'text'],
                'settings_module' => 'savings-group',
            ],
            [
                'settings_name' => 'sacco-savings-group-code-segment-length',
                'settings_status' => 'active',
                'settings_action' => ['action' => 3, 'attr' => 'number'],
                'settings_module' => 'savings-group',
                'settings_action_description' => 'Number of digits to pad member codes with zeros. codeprefix-000-000-5',
                'settings_setting_description' => 'Defines the total length of the numeric part of segments',
            ],
            [
                'settings_name' => 'sacco-savings-group-free-input-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'savings-group',
            ],
            [
                'settings_name' => 'sacco-savings-group-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'savings-group',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],
            codeCustomeGenerator('share', 'sacco-Share-code-custom-generator'),
            [
                'settings_name' => 'sacco-Share-code-str-pad',
                'settings_status' => 'active',
                'settings_action' => ['action' => '7', 'attr' => 'text'],
                'settings_module' => 'share',
                'settings_action_description' => 'Number of digits to pad member codes with zeros. codeprefix-0000005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.MBRC-00000090',

            ],
            [
                'settings_name' => 'sacco-Share-code-auto-generate',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'share',
                'settings_action_description' => 'the code will be auto generated with the code prefix provided /default code prefix will be used',
                'settings_setting_description' => 'the code will be auto generated with the code prefix provided /default code prefix will be used',
            ],

            [
                'settings_name' => 'sacco-Share-code-prefix',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'SSC-', 'attr' => 'text'],
                'settings_module' => 'share',
            ],
            [
                'settings_name' => 'sacco-Share-code-segment-length',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'number'],
                'settings_module' => 'share',
            ],
            [
                'settings_name' => 'sacco-Share-free-input-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'share',
            ],
            [
                'settings_name' => 'sacco-share-on-member-creation-create-share-account-at-the-same-time',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'members-onboarding',
                'settings_action_description' => "Automatically create a member's share account during member registration.",
                'settings_setting_description' => 'When enabled, the system will automatically create a share account for the member at the time of registration. When disabled, the share account must be created manually after the member has been registered.',
            ],
            [
                'settings_name' => 'sacco-share-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'share',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],
            [
                'settings_name' => 'sacco-share-hide-shareholder-field',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'share',
                'settings_action_description' => 'Hides the shareholder field from the member registration form.',
                'settings_setting_description' => 'When enabled, the shareholder field will be hidden from the member registration form. This means that the shareholder field will not be visible to the user during the registration process.',
            ],
            [
                'settings_name' => 'sacco-share-maximum-share-numbers-one-should-have',
                'settings_status' => 'active',
                'settings_action' => ['action' => 2, 'attr' => 'number'],
                'settings_module' => 'share',
                'settings_action_description' => 'Specifies the maximum share value shoyld one have .',
                'settings_setting_description' => 'This setting defines the maximum share amount a member can have when their share account is created during registration. The system uses this value as the maximum share balance allowed for a member.',
            ],
            [
                'settings_name' => 'sacco-share-on-member-creation-create-share-minimum-value',
                'settings_status' => 'active',
                'settings_action' => ['action' => 2, 'attr' => 'number'],
                'settings_module' => 'share',
                'settings_action_description' => 'Specifies the minimum share value assigned when a share account is automatically created for a member (e.g., 50).',
                'settings_setting_description' => 'This setting defines the minimum share amount a member must have when their share account is created during registration. The system uses this value as the starting share balance to ensure all members meet the required minimum shareholding.',
            ],
            [
                'settings_name' => 'sacco-share-price-value',
                'settings_status' => 'active',
                'settings_action' => ['action' => 12, 'attr' => 'number'],
                'settings_module' => 'share',

                'settings_action_description' => 'Defines the default share price value assigned to a member during share account creation (e.g., minimum value of 50).',

                'settings_setting_description' => 'This setting determines the value of a single share unit for members. When creating or assigning shares to a member, the system will use this value as the base price per share. It helps standardize share pricing across the SACCO.',
            ],
            [
                'settings_name' => 'sacco-share-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'share',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],
            codeCustomeGenerator('staff-on-boarding', 'sacco-Staff-code-custom-generator'),
            [
                'settings_name' => 'sacco-Staff-code-str-pad',
                'settings_status' => 'active',
                'settings_action' => ['action' => '7', 'attr' => 'text'],

                'settings_module' => 'staff-on-boarding',
            ],
            [
                'settings_name' => 'sacco-Staff-code-auto-generate',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'Staff-on-boarding',
            ],

            [
                'settings_name' => 'sacco-Staff-code-prefix',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'SSTC-', 'attr' => 'text'],
                'settings_module' => 'staff-on-boarding',

            ],
            [
                'settings_name' => 'sacco-Staff-code-segment-length',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'number'],
                'settings_module' => 'staff-on-boarding',

            ],

            [
                'settings_name' => 'sacco-Staff-free-input-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'staff-on-boarding',

            ],
            [
                'settings_name' => 'sacco-staff-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'staff-on-boarding',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],
            codeCustomeGenerator('transactions', 'sacco-transactions-code-custom-generator'),

            [
                'settings_name' => 'sacco-transactions-code-str-pad',
                'settings_status' => 'active',
                'settings_action' => ['action' => '7', 'attr' => 'text'],

                'settings_module' => 'transactions',
                'settings_action_description' => 'Number of digits to pad member codes with zeros. codeprefix-0000005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.MBRC-00000090',

            ],

            [
                'settings_name' => 'sacco-transactions-code-auto-generate',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'transactions',

            ],

            [
                'settings_name' => 'sacco-transactions-code-prefix',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'SSTC-', 'attr' => 'text'],
                'settings_module' => 'transactions',

            ],
            [
                'settings_name' => 'sacco-transactions-code-segment-length',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'number'],
                'settings_module' => 'transactions',

            ],

            [
                'settings_name' => 'sacco-transactions-free-input-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'transactions',

            ],
            [
                'settings_name' => 'sacco-transactions-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'transactions',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],
            codeCustomeGenerator('saving-products', 'sacco-saving-products-code-custom-generator'),


            [
                'settings_name' => 'sacco-saving-products-code-str-pad',
                'settings_status' => 'active',
                'settings_action' => ['action' => '7', 'attr' => 'text'],
                'settings_module' => 'saving-products',
                'settings_action_description' => 'Number of digits to pad member codes with zeros. codeprefix-0000005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.MBRC-00000090',

            ],
            [
                'settings_name' => 'sacco-saving-products-code-auto-generate',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'saving-products',

            ],

            [
                'settings_name' => 'sacco-saving-products-code-prefix',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'SSPC-', 'attr' => 'text'],
                'settings_module' => 'saving-products',

            ],
            [
                'settings_name' => 'sacco-saving-products-code-segment-length',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'number'],
                'settings_module' => 'saving-products',

            ],

            [
                'settings_name' => 'sacco-saving-products-free-input-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'saving-products',

            ],
            codeCustomeGenerator('saving-products', 'sacco-savings-products-code-custom-generator'),

            [
                'settings_name' => 'sacco-savings-products-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'savings-products',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],

            codeCustomeGenerator('savings-accounts', 'sacco-savings-accounts-code-custom-generator'),

            [
                'settings_name' => 'sacco-savings-accounts-code-str-pad',
                'settings_status' => 'active',
                'settings_action' => ['action' => '7', 'attr' => 'text'],
                'settings_module' => 'savings-accounts',
                'settings_action_description' => 'Number of digits to pad member codes with zeros. codeprefix-0000005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.MBRC-00000090',
            ],
            [
                'settings_name' => 'sacco-savings-accounts-code-auto-generate',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'savings-accounts',
            ],
            [
                'settings_name' => 'sacco-savings-accounts-consider-minimum-balance',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'savings-accounts',
            ],

            [
                'settings_name' => 'sacco-savings-accounts-code-prefix',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'SSAC-', 'attr' => 'text'],
                'settings_module' => 'savings-accounts',
            ],
            [
                'settings_name' => 'sacco-savings-accounts-code-segment-length',
                'settings_status' => 'active',
                'settings_module' => 'savings-accounts',
                'settings_action' => ['action' => 1, 'attr' => 'number'],
            ],
            [
                'settings_name' => 'sacco-savings-accounts-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'savings-accounts',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],
            [
                'settings_name' => 'sacco-savings-accounts-free-input-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'savings-accounts',
            ],
            codeCustomeGenerator('transfer-savings', 'sacco-transfer-savings-code-custom-generator'),

            [
                'settings_name' => 'sacco-transfer-savings-code-str-pad',
                'settings_status' => 'active',
                'settings_action' => ['action' => '7', 'attr' => 'text'],
                'settings_module' => 'transfer-savings',
                'settings_action_description' => 'Number of digits to pad member codes with zeros. codeprefix-0000005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.STS-00000090',
            ],
            [
                'settings_name' => 'sacco-transfer-savings-code-auto-generate',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'transfer-savings',
            ],
            [
                'settings_name' => 'sacco-transfer-savings-code-prefix',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'STS-', 'attr' => 'text'],
                'settings_module' => 'transfer-savings',
            ],
            [
                'settings_name' => 'sacco-transfer-savings-code-segment-length',
                'settings_status' => 'active',
                'settings_module' => 'transfer-savings',
                'settings_action' => ['action' => 1, 'attr' => 'number'],
                'settings_action_description' => 'Number of digits to segment member codes with zeros. codeprefix-000-0005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.STS-000-00090',
            ],
            [
                'settings_name' => 'sacco-transfer-savings-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'transfer-savings',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],
            [
                'settings_name' => 'sacco-transfer-savings-free-input-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'transfer-savings',
            ],
            [
                'settings_name' => 'sacco-transfer-savings-Require-approval-to-become-acomplete-transfer',
                'settings_status' => 'active',
                'settings_action' => ['action' => '1', 'attr' => 'switch'],
                'settings_module' => 'transfer-savings',
                'settings_action_description' => 'Require staff approval before a transfer is completed.',
                'settings_setting_description' => 'When enabled, transfers remain pending until approved. When disabled, transfers are completed instantly.',

            ],
            codeCustomeGenerator('loan-application', 'sacco-loan-products-code-custom-generator'),

            [
                'settings_name' => 'sacco-loan-products-free-input-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'loan-application',

            ],
            [
                'settings_name' => 'sacco-loan-products-code-auto-generate',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'loan-application',

            ],
            [
                'settings_name' => 'sacco-loan-products-code-prefix',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'SLP-', 'attr' => 'text'],
                'settings_module' => 'loan-application',

            ],
            [
                'settings_name' => 'sacco-loan-products-code-segment-length',
                'settings_status' => 'active',
                'settings_module' => 'loan-application',
                'settings_action' => ['action' => 1, 'attr' => 'number'],
                'settings_action_description' => 'Number of digits to segment member codes with zeros. codeprefix-000-0005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.SLP-000-00090',
            ],
            [
                'settings_name' => 'sacco-loan-products-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'loan-application',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],

            [
                'settings_name' => 'sacco-loan-products-code-str-pad',
                'settings_status' => 'active',
                'settings_action' => ['action' => 7, 'attr' => 'number'],
                'settings_module' => 'loan-application',
                'settings_action_description' => 'Number of digits to pad member codes with zeros. codeprefix-0000005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.SLP-00000090',
            ],

            [
                'settings_name' => 'sacco-loan-application-code-str-pad',
                'settings_status' => 'active',
                'settings_action' => ['action' => '7', 'attr' => 'text'],
                'settings_module' => 'loan-application',
                'settings_action_description' => 'Number of digits to pad member codes with zeros. codeprefix-0000005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.STS-00000090',
            ],
            [
                'settings_name' => 'sacco-loan-application-code-auto-generate',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'loan-application',
            ],
            [
                'settings_name' => 'sacco-loan-application-code-prefix',
                'settings_status' => 'active',
                'settings_action' => ['action' => 'LN-', 'attr' => 'text'],
                'settings_module' => 'loan-application',
            ],
            [
                'settings_name' => 'sacco-loan-application-code-segment-length',
                'settings_status' => 'active',
                'settings_module' => 'loan-application',
                'settings_action' => ['action' => 5, 'attr' => 'number'],
                'settings_action_description' => 'Number of digits to segment member codes with zeros. codeprefix-000-0005',
                'settings_setting_description' => 'Defines the total length of the numeric part of the member code.STS-000-00090',
            ],
            [
                'settings_name' => 'sacco-loan-application-code-base-on-last-input',
                'settings_status' => 'active',
                'settings_module' => 'loan-application',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
            ],
            [
                'settings_name' => 'sacco-loan-application-free-input-code',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'loan-application',
            ],
            [
                'settings_name' => 'sacco-loan-member-on-loan-application-can-be-guaranteed-by-other-group',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'loan-application',
                'settings_action_description' => 'A loan applicant can be guaranteed by members of other groups',
                'settings_setting_description' => 'When enabled, members applying for a loan can be guaranteed by other groups. When disabled, only members within the same group can act as guarantors.',
            ],
            // /////////////////////// notifications

            [
                'settings_name' => 'sacco-On-sell-of-shares',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-membership-change',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-loan-closing',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-loan-payment',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-loan-due-date-warning',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-committee-action-on-the-loan',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-opening-another-account',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-loan-writing-off',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-loan-waiving-off',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-loan-rejecting',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-loan-disbursement',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-loan-approval',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-loan-appraisal',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-loan-application',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-deposit-transaction',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-transfer-transaction',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-withdraw-transaction',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-On-new-member-registration',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'sacco-notify-the-guarantor',
                'settings_status' => 'active',
                'settings_action' => ['action' => 1, 'attr' => 'switch'],
                'settings_module' => 'system-sms-notifications',
            ],
            [
                'settings_name' => 'system-used-by-money-lenders',
                'settings_status' => 'active',
                'settings_action' => ['action' => 0, 'attr' => 'switch'],
                'settings_module' => 'members-onboarding',
                // 'settings_module' => 'system-Security',
            ],

        ];

        $settings = array_map(function ($item) {
            $pertern = '/[^A-z0-9 ]/ig';
            $item['created_at'] = now();
            $item['system_type'] = 'system';
            $lowerCasename = strtolower($item['settings_name']);
            $lowerCaseModulename = strtolower($item['settings_module']);

            $item['settings_action'] = json_encode($item['settings_action']);
            $item['settings_name'] = str_replace($pertern, '-', $lowerCasename);
            $item['settings_module'] = $lowerCaseModulename;
            $item['settings_action_description'] = $item['settings_action_description'] ?? null;
            $item['settings_setting_description'] = $item['settings_setting_description'] ?? null;

            return $item;
        }, $settings);
        // Log::info($settings);

        $requiredColumns = [
            'settings_module' => 'string',
            'settings_name' => 'string',
            'settings_status' => 'string',
            'settings_action' => 'json',
            'system_type' => 'string',
            'settings_setting_description' => 'string',
            'settings_action_description' => 'string',
            'created_at' => 'timestamp',
        ];
        $global = new GlobalHelpers;
        foreach ($tenants as $tenant) {
            $tableName = $tenant->database_name . '.system_settings';
            $global->GeneratDBColumnsAndInsetData($tableName, $settings, $requiredColumns);
        }
    }
}
