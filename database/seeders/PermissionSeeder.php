<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
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
                'action' => 'dashboard-view-total-tenants',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  total tenants',
            ],
            [
                'action' => 'dashboard-view-active-tenants',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  active tenants',
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
                'action' => 'dashboard-view-top-tenants',
                'parent_module' => 'dashboard',

                'description' => 'dashboard permission view the main link that holds the children  top tenants',
            ],
            [
                'action' => 'licenses-module-link-view',
                'parent_module' => 'licenses',

                'description' => 'licenses permission view the main link that holds the children ',
            ],
            [
                'action' => 'licenses-create',
                'parent_module' => 'licenses',

                'description' => 'licenses permission create',
            ],
            [
                'action' => 'licenses-update',
                'parent_module' => 'licenses',

                'description' => 'licenses permission update',
            ],
            [
                'action' => 'licenses-delete',
                'parent_module' => 'licenses',

                'description' => 'licenses permission delete',
            ],
            [
                'action' => 'licenses-pernament-delete',
                'parent_module' => 'licenses',

                'description' => 'licenses permission delete pernament',
            ],
            [
                'action' => 'licenses-list',
                'parent_module' => 'licenses',

                'description' => 'licenses permission list',
            ],
            [
                'action' => 'licenses-view-table-details',
                'parent_module' => 'licenses',

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
                'action' => 'tenants-list',
                'parent_module' => 'tenants',

                'description' => 'tenants permission list',
            ],
            [
                'action' => 'tenants-view-table-details',
                'parent_module' => 'tenants',

                'description' => 'tenants permission view table details',
            ],
            [
                'action' => 'tenants-module-link-view',
                'parent_module' => 'tenants',

                'description' => 'tenants permission view the main link that holds the children ',
            ],
            [
                'action' => 'tenants-create',
                'parent_module' => 'tenants',

                'description' => 'tenants permission create',
            ],
            [
                'action' => 'tenants-update',
                'parent_module' => 'tenants',

                'description' => 'tenants permission update',
            ],
            [
                'action' => 'tenants-delete',
                'parent_module' => 'tenants',

                'description' => 'tenants permission delete',
            ],
            [
                'action' => 'tenants-pernament-delete',
                'parent_module' => 'tenants',

                'description' => 'tenants permission delete pernament',
            ],
            [
                'action' => 'settings-module-link-view',
                'parent_module' => 'settings',

                'description' => 'settings permission view the main link that holds the children ',
            ],
            [
                'action' => 'settings-general-view',
                'parent_module' => 'settings',

                'description' => 'settings permission view the main link that holds the children general settings',
            ],
            [
                'action' => 'settings-permission-view',
                'parent_module' => 'settings',

                'description' => 'settings permission view the main link that holds the children tenant settings',
            ],
            [
                'action' => 'settings-permission-staff-view',
                'parent_module' => 'settings',

                'description' => 'settings permission view the main link that holds the children staff settings',
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
                'action' => 'settings-link-view',
                'parent_module' => 'settings',
                'description' => 'settings permission view the main link that holds the children staff settings',
            ],
            [
                'action' => 'settings-roles-update',
                'parent_module' => 'settings',

                'description' => 'staff has permission to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-list',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-holders-list',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-create',
                'parent_module' => 'settings',
                'description' => 'staff has permission to create new roles for the user',
            ],
            [
                'action' => 'settings-roles-delete',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-details',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-remove-ability',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter roles for the user',
            ],
            [
                'action' => 'settings-roles-add-ability',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter roles for the user',
            ],
            [
                'action' => 'settings-plans-update',
                'parent_module' => 'settings',

                'description' => 'staff has permission to alter plans for the user',
            ],
            [
                'action' => 'settings-plans-list',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter plans for the user',
            ],
            [
                'action' => 'settings-plans-holders-list',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter plans for the user',
            ],
            [
                'action' => 'settings-plans-create',
                'parent_module' => 'settings',
                'description' => 'staff has permission to create new plans for the user',
            ],
            [
                'action' => 'settings-plans-delete',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter plans for the user',
            ],
            [
                'action' => 'settings-plans-details',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter plans for the user',
            ],
            [
                'action' => 'settings-plans-remove-ability',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter plans for the user',
            ],
            [
                'action' => 'settings-plans-add-ability',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter plans for the user',
            ],
            [
                'action' => 'members-update',
                'parent_module' => 'settings',

                'description' => 'staff has permission to alter meber for the user',
            ],
            [
                'action' => 'members-list',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter meber for the user',
            ],

            [
                'action' => 'members-create',
                'parent_module' => 'settings',
                'description' => 'staff has permission to create new meber for the user',
            ],
            [
                'action' => 'members-delete',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter meber for the user',
            ],
            [
                'action' => 'staff-roles-drop-down',
                'parent_module' => 'members',
                'description' => 'staff has permission to see api dropdown detail',
            ],
            [
                'action' => 'members-details',
                'parent_module' => 'settings',
                'description' => 'staff has permission to alter meber for the user',
            ],
            [
                'action' => 'group-savings-list',
                'parent_module' => 'group-savings',

                'description' => 'group-savings permission list',
            ],
            [
                'action' => 'group-saving-view-table-details',
                'parent_module' => 'group-savings',

                'description' => 'view group-savings  table details',
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
            [
                'action' => 'view-capitalization-logs-list',
                'parent_module' => 'settings',
                'description' => 'view  compony capitalization list logs',
            ],
            [
                'action' => 'view-capitalization-list',
                'parent_module' => 'settings',
                'description' => 'view  compony capitalization list',
            ],
            [
                'action' => 'edit-capitalization-list',
                'parent_module' => 'settings',
                'description' => 'edit  compony capitalization list',
            ],
            [
                'action' => 'create-capitalization',
                'parent_module' => 'settings',
                'description' => 'add  compony capitalization list',
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
