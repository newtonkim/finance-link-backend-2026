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
                ->when(! empty($module), function ($query) use ($module) {
                    $query->whereIn('settings_module', $module);
                })
                ->when(! empty($settings_names), function ($query) use ($settings_names) {
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
    public function addAmemberIntoAgroup($req, $addingTothegroup = false, bool $syncMembers = false)
    {
        return $this->TryCatch(function () use ($req, $syncMembers) {
            $codeSequence = new CodeSequence;
            $CrudHelders = new CrudHelders;
            if (isset($req['memberslist'])) {
                // memberslist may arrive as an array (multi-select) or a comma string.
                $ArrayMember = is_array($req['memberslist'])
                    ? array_values($req['memberslist'])
                    : explode(',', (string) $req['memberslist']);
                $ArrayMember = array_values(array_filter($ArrayMember, fn ($m) => $m !== null && $m !== ''));
                if ($syncMembers) {
                    DB::table('savings_group_members')
                        ->where('savings_group_id', $req['group_id'])
                        ->whereNotIn('member_id', $ArrayMember)
                        ->update([
                            'deleted_at' => now(),
                            'deleted_by' => auth()->check() ? auth()->id() : null,
                            'updated_at' => now(),
                            'updated_by' => auth()->check() ? auth()->id() : null,
                        ]);

                    DB::table('savings_group_members')
                        ->where('savings_group_id', $req['group_id'])
                        ->whereIn('member_id', $ArrayMember)
                        ->whereNotNull('deleted_at')
                        ->update([
                            'deleted_at' => null,
                            'deleted_by' => null,
                            'updated_at' => now(),
                            'updated_by' => auth()->check() ? auth()->id() : null,
                        ]);
                }
                // Explicit role chosen when adding members. Officer roles
                // (chairman/treasurer/secretary) also become withdrawal approvers.
                $allowedRoles = ['chairman', 'treasurer', 'secretary', 'member'];
                $requestedRole = strtolower((string) ($req['member_role'] ?? 'member'));
                $memberRole = in_array($requestedRole, $allowedRoles, true) ? $requestedRole : 'member';
                $isOfficer = $memberRole !== 'member';

                $length = count($ArrayMember);
                for ($i = 0; $i < $length; $i++) {
                    $memberId = $ArrayMember[$i];

                    // Skip members already in the group so re-saving (e.g. on edit)
                    // never duplicates memberships.
                    $alreadyIn = DB::table('savings_group_members')
                        ->where('savings_group_id', $req['group_id'])
                        ->where('member_id', $memberId)
                        ->whereNull('deleted_at')
                        ->exists();
                    if ($alreadyIn) {
                        continue;
                    }

                    $groupMemebrCode = $codeSequence->codeSequence($req['code'] ?? null, type: 'savings-group', moduleTarget: 'savings-group', tableTaget: 'savings_group_members');
                    $restored = DB::table('savings_group_members')
                        ->where('savings_group_id', $req['group_id'])
                        ->where('member_id', $memberId)
                        ->whereNotNull('deleted_at')
                        ->update([
                            'group_account_id' => $req['group_account_id'] ?? null,
                            'account_number' => $groupMemebrCode,
                            'role' => $memberRole,
                            'is_approver' => $isOfficer,
                            'approver_role' => $isOfficer ? $memberRole : null,
                            'code' => $groupMemebrCode,
                            'branch_id' => request()->branch_id,
                            'deleted_at' => null,
                            'deleted_by' => null,
                            'updated_at' => now(),
                            'updated_by' => auth()->check() ? auth()->id() : null,
                        ]);
                    if ($restored) {
                        continue;
                    }

                    DB::table('savings_group_members')->insert([
                        'group_account_id' => $req['group_account_id'] ?? null,
                        'savings_group_id' => $req['group_id'],
                        'account_number' => $groupMemebrCode,
                        'role' => $memberRole,
                        'is_approver' => $isOfficer,
                        'approver_role' => $isOfficer ? $memberRole : null,
                        'code' => $groupMemebrCode,
                        'member_id' => $memberId,
                        'branch_id' => request()->branch_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'created_by' => auth()->check() ? auth()->id() : null,
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
