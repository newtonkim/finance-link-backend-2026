<?php

namespace App\Services;

use App\Http\Globals\GlobalHelpers;
use Illuminate\Support\Facades\DB;

class Authservice extends GlobalHelpers
{
    public function collectPermission($id, bool $isTenantAdmin = false)
    {
        // Tenant admins get all permissions — no restriction needed
        if ($isTenantAdmin) {
            return DB::table('permissions')->pluck('action')->all();
        }

        $userPermissions = DB::table('permissions_users')->where('user_id', $id)
            ->value('permission_ids');
        $permissions = json_decode($userPermissions, true) ?? [];
        $allowedActions = DB::table('permissions')
            ->whereIn('id', $permissions)
            ->pluck('action');

        return $allowedActions->all();
    }

    /**
     * collect all system settings
     */
    public function collectAllSystemSetting()
    {
        return $this->TryCatch(function () {
            // dont touch this  this gives you all system settings  without any restriction and also gives you the action description as an array if its in json format
            $structure = [];
            DB::table('system_settings')
                ->where('settings_status', 'active')
                ->orderBy('id', 'desc')
                ->select(['id', 'settings_name As name', 'settings_status', 'settings_setting_description AS description', 'settings_action_description AS actiondescription', 'settings_action AS settings_action'])
                ->chunk(400, function ($settings) use (&$structure) {
                    foreach ($settings as $setting) {
                        $action = $this->isJSONToArray(json_decode($setting->settings_action, true));
                        $structure[$setting->name] = $action['action'] == 'true' ? true : $action['action'];
                        // $structure[$setting->name] = $this->isJSONToArray(json_decode($setting->actiondescription, true));
                    }
                });

            return $structure;

        });
    }

    public function collectSystemBranding()
    {

        return $this->TryCatch(function () {

            $branding = DB::table('sacco_branding')
                ->orderBy('id', 'desc')
                ->first(['id', 'sacco_name AS name',  'tagline AS tag', 'logo_path AS logo']);

            return $branding;
        });
    }
}
