<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeederTenants extends Seeder
{
    public function run(): void
    {
        $permissionsList = [
            [
                'action' => 'dashboard-module-link-view',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children ',
            ],
            [
                'action' => 'dashboard-view-total-members',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  total members',
            ],
            [
                'action' => 'dashboard-view-active-members',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  active members',
            ],
            [
                'action' => 'dashboard-view-monthly-revenue',
                'parent_module' => 'dashboard',
                'description' => 'dashboard permission view the main link that holds the children  monthly revenue',
            ],
            [
                'action' => 'dashboard-view-annual-revenue',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  annual revenue',
            ],
            [
                'action' => 'dashboard-view-expiring-licenses',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  expiring licenses',
            ],
            [
                'action' => 'dashboard-view-expired-licenses',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  expired licenses',
            ],
            [
                'action' => 'dashboard-view-suspended-users',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  suspended users',
            ],
            [
                'action' => 'dashboard-view-graph-analysis',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  graph analysis',
            ],
            [
                'action' => 'dashboard-view-recent-activity',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  recent activity',
            ],
            [
                'action' => 'dashboard-view-top-members',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  top members',
            ],
            [
                'action' => 'members-account-module-link-view',
                'parent_module' => 'members-account',

                'description' => 'licenses permission view the main link that holds the children ',
            ],
            [
                'action' => 'create-members-account',
                'parent_module' => 'members-account',
                'description' => 'licenses permission create',
            ],
            [
                'action' => 'update-members-account',
                'parent_module' => 'members-account',
                'description' => 'licenses permission update',
            ],

            [
                'action' => 'delete-members-account',
                'parent_module' => 'members-account',
                'description' => 'licenses permission delete',
            ],
            [
                'action' => 'pernament-delete-members-account',
                'parent_module' => 'members-account',
                'description' => 'licenses permission delete pernament',
            ],
            [
                'action' => 'list-members-account',
                'parent_module' => 'members-account',
                'description' => 'licenses permission list',
            ],
            [
                'action' => 'members-account-details',
                'parent_module' => 'members-account',
                'description' => 'licenses permission view table details',
            ],
            [
                'action' => 'staff-module-link-view',
                'parent_module' => 'staff',

                'description' => 'staff permission view the main link that holds the children ',
            ],
            [
                'action' => 'staff-create',
                'parent_module' => 'staff',

                'description' => 'staff permission create',
            ],
            [
                'action' => 'staff-update',
                'parent_module' => 'staff',

                'description' => 'staff permission update',
            ],
            [
                'action' => 'staff-delete',
                'parent_module' => 'staff',

                'description' => 'staff permission delete',
            ],
            [
                'action' => 'staff-details',
                'parent_module' => 'staff',

                'description' => 'staff permission details',
            ],
            [
                'action' => 'staff-pernament-delete',
                'parent_module' => 'staff',

                'description' => 'staff permission delete pernament',
            ],
            [
                'action' => 'staff-list',
                'parent_module' => 'staff',

                'description' => 'staff permission list',
            ],
            [
                'action' => 'staff-view-table-details',
                'parent_module' => 'staff',

                'description' => 'staff permission view table details',
            ],

            [
                'action' => 'members-module-parent-dropdown',
                'parent_module' => 'members',

                'description' => 'the full nav link it self',
            ],
            [
                'action' => 'members-list',
                'parent_module' => 'members',

                'description' => 'members permission list',
            ],
            [
                'action' => 'members-view-table-details',
                'parent_module' => 'members',

                'description' => 'view members  table details',
            ],
            [
                'action' => 'members-module-link-view',
                'parent_module' => 'members',

                'description' => 'members permission view the main link that holds the children ',
            ],
            [
                'action' => 'members-create',
                'parent_module' => 'members',

                'description' => 'members permission create',
            ],
            [
                'action' => 'members-update',
                'parent_module' => 'members',

                'description' => 'members permission update',
            ],
            [
                'action' => 'members-delete',
                'parent_module' => 'members',

                'description' => 'members permission delete',
            ],
            [
                'action' => 'members-details',
                'parent_module' => 'members',
                'description' => 'members permission to details',
            ],
            [
                'action' => 'members-pernament-delete',
                'parent_module' => 'members',

                'description' => 'members permission delete pernament',
            ],
            [
                'action' => 'settings-permissions-list',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter permissions for the user',
            ],
            [
                'action' => 'settings-permissions-update',
                'parent_module' => 'settings',

                'description' => 'staff has permission to alter permissions for the user',
            ],
            [
                'action' => 'settings-permissions-list',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter permissions for the user',
            ],
            [
                'action' => 'settings-permissions-holders-list',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter permissions for the user',
            ],
            [
                'action' => 'settings-permissions-delete',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter permissions for the user',
            ],
            [
                'action' => 'settings-permissions-remove-ability',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter permissions for the user',
            ],
            [
                'action' => 'settings-permissions-add-ability',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter permissions for the user',
            ],
            [
                'action' => 'settings-roles-list',
                'parent_module' => 'settings',
                'description' => 'staff has roles to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-update',
                'parent_module' => 'settings',

                'description' => 'staff has roles to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-list',
                'parent_module' => 'settings',
                'description' => 'staff has roles to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-holders-list',
                'parent_module' => 'settings',
                'description' => 'staff has roles to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-delete',
                'parent_module' => 'settings',
                'description' => 'staff has roles to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-remove-ability',
                'parent_module' => 'settings',
                'description' => 'staff has roles to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-add-ability',
                'parent_module' => 'settings',
                'description' => 'staff has roles to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-details',
                'parent_module' => 'settings',
                'description' => 'staff has roles to alter roles for the user',
            ],
            [
                'action' => 'group-savings-module-link-view',
                'parent_module' => 'group-savings',
                'description' => 'view group-savings link',
            ],
            [
                'action' => 'can_finalise_loan',
                'parent_module' => 'staff',
                'description' => 'can finalise loan',
            ],
            [
                'action' => 'can_vote_on_loans',
                'parent_module' => 'staff',
                'description' => 'can vote on loans',
            ],
            [
                'action' => 'can_manage_branch',
                'parent_module' => 'staff',
                'description' => 'view savings link',
            ],

            [
                'action' => 'savings-transfer-module-link-view',
                'parent_module' => 'savings-transfer',
                'description' => 'view savings-transfer link',
            ],
            [
                'action' => 'loans-module-link-view',
                'parent_module' => 'loans',
                'description' => 'view loans link',
            ],
            [
                'action' => 'chart-of-accounts-module-link-view',
                'parent_module' => 'chart-of-accounts',
                'description' => 'view chart-of-accounts link',
            ],
            [
                'action' => 'transfer-module-link-view',
                'parent_module' => 'transfer',
                'description' => 'view transfer link',
            ],
            [
                'action' => 'settings-module-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings link',
            ],
            [
                'action' => 'settings-Organisation-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings Organisation link',
            ],
            [
                'action' => 'settings-Members-Roles-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings Members Roles link',
            ],
            [
                'action' => 'settings-Loan-Products-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings Loan Products link',
            ],
            [
                'action' => 'settings-Savings-Products-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings Savings Products link',
            ],
            [
                'action' => 'settings-Shares-Dividends-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings Shares & Dividends link',
            ],
            [
                'action' => 'settings-transaction-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings transaction link',
            ],

            [
                'action' => 'settings-Accounting-GL-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings Accounting & GL link',
            ],
            [
                'action' => 'settings-Compliance-Audit-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings Compliance & Audit link',
            ],
            [
                'action' => 'settings-System-Security-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings System & Securitylink',
            ],
            [
                'action' => 'settings-Public-Holidays-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings Public Holidays & Leave link',
            ],
            [
                'action' => 'settings-notifications-link-view',
                'parent_module' => 'settings',
                'description' => 'view settings notifications link',
            ],
            [
                'action' => 'group-savings-list',
                'parent_module' => 'group-savings',

                'description' => 'group-savings permission list',
            ],
            [
                'action' => 'group-saving-module-link-view',
                'parent_module' => 'group-savings',

                'description' => 'group-savings permission view the main link that holds the children ',
            ],
            [
                'action' => 'group-saving-create',
                'parent_module' => 'group-savings',

                'description' => 'group-savings permission create',
            ],
            [
                'action' => 'group-saving-update',
                'parent_module' => 'group-savings',

                'description' => 'group-savings permission update',
            ],
            [
                'action' => 'group-saving-delete',
                'parent_module' => 'group-savings',

                'description' => 'group-savings permission delete',
            ],
            [
                'action' => 'group-saving-details',
                'parent_module' => 'group-savings',
                'description' => 'group-savings permission to details',
            ],
            [
                'action' => 'group-saving-pernament-delete',
                'parent_module' => 'group-savings',

                'description' => 'group-savings permission delete pernament',
            ],
            [
                'action' => 'savings-transfer-list',
                'parent_module' => 'savings-transfer',

                'description' => 'savings-transfer permission list',
            ],
            [
                'action' => 'transfer-saving-details',
                'parent_module' => 'savings-transfer',

                'description' => 'view savings-transfer  table details',
            ],
            [
                'action' => 'transfer-saving-module-link-view',
                'parent_module' => 'savings-transfer',

                'description' => 'savings-transfer permission view the main link that holds the children ',
            ],
            [
                'action' => 'transfer-saving-create',
                'parent_module' => 'savings-transfer',

                'description' => 'savings-transfer permission create',
            ],
            [
                'action' => 'transfer-saving-update',
                'parent_module' => 'savings-transfer',

                'description' => 'savings-transfer permission update',
            ],
            [
                'action' => 'transfer-saving-delete',
                'parent_module' => 'savings-transfer',
                'description' => 'savings-transfer permission delete',
            ],
            [
                'action' => 'transfer-saving-details',
                'parent_module' => 'savings-transfer',
                'description' => 'savings-transfer permission to details',
            ],
            [
                'action' => 'transfer-saving-pernament-delete',
                'parent_module' => 'savings-transfer',

                'description' => 'savings-transfer permission delete pernament',
            ],
            //  [
            //     'action' => 'branch-create',
            //     'parent_module' => 'branch',

            //     'description' => 'branch permission create',
            // ],
            [
                'action' => 'branch-update',
                'parent_module' => 'branch',

                'description' => 'branch permission update',
            ],
            // [
            //     'action' => 'branch-delete',
            //     'parent_module' => 'branch',

            //     'description' => 'branch permission delete',
            // ],
            [
                'action' => 'branch-details',
                'parent_module' => 'branch',

                'description' => 'branch permission details',
            ],
            [
                'action' => 'branch-pernament-delete',
                'parent_module' => 'branch',

                'description' => 'branch permission delete pernament',
            ],
            [
                'action' => 'branch-list',
                'parent_module' => 'branch',

                'description' => 'branch permission list',
            ],
            [
                'action' => 'branch-view-table-details',
                'parent_module' => 'branch',

                'description' => 'branch permission view table details',
            ],
            [
                'action' => 'general-charges-create',
                'parent_module' => 'general-charges',

                'description' => 'general-charges permission create',
            ],
            [
                'action' => 'general-charges-update',
                'parent_module' => 'general-charges',

                'description' => 'general-charges permission update',
            ],
            [
                'action' => 'general-charges-delete',
                'parent_module' => 'general-charges',

                'description' => 'general-charges permission delete',
            ],
            [
                'action' => 'general-charges-details',
                'parent_module' => 'general-charges',

                'description' => 'general-charges permission details',
            ],
            [
                'action' => 'general-charges-pernament-delete',
                'parent_module' => 'general-charges',

                'description' => 'general-charges permission delete pernament',
            ],
            [
                'action' => 'general-charges-list',
                'parent_module' => 'general-charges',

                'description' => 'general-charges permission list',
            ],
            [
                'action' => 'general-charges-view-table-details',
                'parent_module' => 'general-charges',

                'description' => 'general-charges permission view table details',
            ],
            [
                'action' => 'withdrawal-members-account',
                'parent_module' => 'members-account',
                'description' => 'members-account permission withdrawal money',
            ],

        ];
        $seenActions = [];
        $uniquePermissions = [];

        foreach ($permissionsList as $item) {
            $action = str_replace('_', '-', $item['action']);
            if (! in_array($action, $seenActions)) {
                $item['action'] = $action;
                $item['created_at'] = now();
                $item['system_type'] = 'system';
                $uniquePermissions[] = $item;
                $seenActions[] = $action;
            }
        }

        DB::table('permissions')->insertOrIgnore($uniquePermissions);
    }
}
