<?php

namespace App\Tenant\Services\TenantSavingsAcountServices;

use App\Http\Globals\GlobalHelpers;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use Illuminate\Support\Facades\DB;

class OtherHelpers extends GlobalHelpers
{
    public function SettingsListPreparation($module = [], $settings_names = [])
    {
        // this   is used in more places
        return $this->TryCatch(function () use ($module, $settings_names) {
            $settings = DB::table('system_settings')
                ->when(!empty($module), function ($query) use ($module) {
                    $query->whereIn('settings_module', $module);
                })
                ->when(!empty($settings_names), function ($query) use ($settings_names) {
                    $query->orWhereIn('settings_name', $settings_names);
                })
                ->orderBy('id', 'DESC')
                ->get(['settings_name', 'settings_action', 'id', 'settings_setting_description', 'settings_action_description']);
            $structure = [];
            foreach ($settings as $setting) {
                $structure[$setting->settings_name] = [
                    'id' => $setting->id,
                    'name' => $setting->settings_name,
                    'description' => $setting->settings_setting_description,
                    'actiondescription' => $setting->settings_action_description,
                    'settings_action' => json_decode($setting->settings_action, true),
                ];
            }

            return $structure;
        });
    }

    /***         /// this is used in more places
     * @param memberslist
     * @param group_id
     * @param id  help not distur the code  that already exists
     * not required code
     * **/
    public function addAmemberIntoAgroup($req, $addingTothegroup = false)
    {
        return $this->TryCatch(function () use ($req, $addingTothegroup) {
            $codeSequence = new CodeSequence;
            $CrudHelders = new CrudHelders;
            if (isset($req['memberslist'])) {
                $ArrayMember = explode(',', $req['memberslist']);
                $length = count($ArrayMember);
                for ($i = 0; $i < $length; $i++) {
                    if (! isset($req['id'])) {
                        $groupMemebrCode = $codeSequence->codeSequence($req['code'] ?? null, type: 'savings-group', moduleTarget: 'savings-group', tableTaget: 'savings_group_members');
                    }
                    $this->UpdateOrCreateRecord('savings_group_members', [
                        'group_account_id' => $req['group_account_id'] ?? null,
                        'savings_group_id' => $req['group_id'],
                        'account_number' => $groupMemebrCode,
                        'role' => $addingTothegroup ? $CrudHelders->groupMemberRoles[4] : $CrudHelders->groupMemberRoles[$i <= 3 ? $i + 1 : 4],
                        'code' => $groupMemebrCode,
                        'member_id' => $ArrayMember[$i],
                        'branch_id' => request()->branch_id,
                    ]);
                }
            }
        });
    }

    public function getAllmemberAccounts($data)
    {
        return $this->TryCatch(function () use ($data) {
            return DB::table('savings_accounts')
                ->Leftjoin('savings_products', 'savings_product_id', '=', 'savings_products.id')
                ->where(['member_id' => $data['member_id'], 'savings_accounts.branch_id' => $data['branch_id']])
                ->whereNull('savings_accounts.deleted_at')
                ->get(['savings_accounts.id', 'savings_accounts.code', 'savings_products.name AS account_type', 'savings_accounts.balance', 'savings_accounts.status', 'savings_accounts.created_at', 'savings_accounts.payment_mod_account_id as payment_mod']);
        });
    }
}
