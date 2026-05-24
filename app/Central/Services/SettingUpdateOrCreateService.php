<?php

namespace App\Central\Services;

use App\Http\Globals\GlobalHelpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SettingUpdateOrCreateService extends GlobalHelpers
{
    public function brandingUpdate()
    {
        return $this->TryCatch(function () {
            $req = request();
            $data = ['updated_at' => now()];

            if ($req->has('platform_name')) {
                $data['platform_name'] = $req->platform_name;
            }
            if ($req->has('tagline')) {
                $data['tagline'] = $req->tagline;
            }
            if ($req->hasFile('logo')) {
                // Delete the old logo if one exists
                $existing = DB::connection('master')->table('central_branding')->value('logo_path');
                if ($existing) {
                    Storage::disk('public')->delete($existing);
                }
                $data['logo_path'] = $req->file('logo')->store('branding', 'public');
            }

            DB::connection('master')->table('central_branding')
                ->updateOrInsert(['id' => 1], $data);

            $service = app(SettingService::class);

            return $service->brandingDetails();
        });
    }

    protected function rolesUOrCFields($req)
    {
        return $this->removeAllNullValues(
            [
                'default_permissions' => ! empty($req['permission']) ? (array_values(array_map('intval', (array) $req['permission']))) : null,
                'name' => $req['name'] ?? null,
                'description' => $req['dec'] ?? null,
            ]
        );
    }

    protected function plansUOrCFields($req)
    {
        return $this->removeAllNullValues(
            [
                'price' => $req['cost'] ?? null,
                'billing_cycle' => $req['billing_type'] ?? null,
                'max_members' => $req['mx_mbrs'] ?? null,
                'max_users' => $req['mxusrs'] ?? null,
                'features' => $req['features'] ?? null,
                'slug' => isset($req['name']) ? $req['name'] : null,
                'name' => isset($req['name']) ? $req['name'].'Plan' : null,
                'days' => isset($req['days']) ? $req['days'] : null,
            ]
        );
    }

    public function plansDelete()
    {
        $this->DeleteRecord('plans', request());
        $Service = app(SettingService::class);

        return $Service->plansList();
    }

    public function plansCreate()
    {
        $features = [
            'reports' => 'reports',
            'loans' => 'loans',
            'savings' => 'savings',
            'shares' => 'shares',
        ];
        $req = request()->all();
        request()->validate([
            'cost' => 'required|min:0|',
            'billing_type' => 'required',
            'mx_mbrs' => 'required',
            'mxusrs' => 'required',
            'features' => 'required',
            'name' => 'required',
        ]);
        $fields = $this->plansUOrCFields($req);
        // / this block helpe in cleaning the features structure
        foreach ($fields as $key2 => $value2) {
            if ($key2 == 'features') {
                $array = array_map(function ($t) {
                    return trim($t, '"');
                }, (array) $value2);
                foreach ($features as $key => $value) {
                    $keyIndex = in_array($value, $array);
                    if ($keyIndex) {
                        $fields['features'][$value] = true;
                    } else {
                        $fields['features'][$value] = false;
                    }
                    foreach ($fields['features'] as $key3 => $value3) {
                        if (is_numeric($key3)) {
                            unset($fields['features'][$key3]);
                        }
                    }
                }
            }
        }
        $fields['created_at'] = now();

        $this->UpdateOrCreateRecord('plans', $fields);
        $Service = app(SettingService::class);

        return $Service->plansList();
    }

    public function permissionRemoveAbility()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $dataCollection = DB::table('permissions_users')
                ->where('user_id', $req->pu)
                ->first(['permission_ids']);

            if ($dataCollection && $dataCollection->permission_ids) {
                $data = json_decode($dataCollection->permission_ids, true); // decode as array
                $key = array_search($req->id, $data);

                if ($key !== false) {
                    unset($data[$key]); // remove the permission
                }

                DB::table('permissions_users')
                    ->where('user_id', $req->pu)
                    ->update(['permission_ids' => (array_values($data))]); // reset keys
            }

            $permissionService = app(SettingService::class);

            return $permissionService->permissionListHolders();
        });
    }

    public function permissionAddAbility()
    {
        $req = request();
        $record = DB::table('permissions_users')
            ->where('user_id', $req->pu)
            ->first(['permission_ids']);
        $permissions = [];
        if ($record && $record->permission_ids) {
            $permissions = json_decode($record->permission_ids, true);
        }
        if (! in_array($req->id, $permissions)) {
            $permissions[] = $req->id;
        }

        DB::table('permissions_users')
            ->updateOrInsert(
                ['user_id' => $req->pu],
                ['permission_ids' => json_encode(array_values($permissions))]
            );
    }

    public function RolesCreate()
    {
        $req = request()->all();
        request()->validate([
            'name' => 'required',
            'permission' => 'required',
        ]);
        $this->UpdateOrCreateRecord('roles', $this->rolesUOrCFields($req));
        $permissionService = app(SettingService::class);

        return $permissionService->RolesListCollection();
    }

    public function RolesAddAbility()
    {
        $req = request()->all();
        request()->validate([
            'id' => 'required',
            'pu' => 'required',
        ]);

        $this->UpdateOrCreateRecord(
            'platform_users',
            ['role_id' => $req['id']],
            ['id' => $req['pu']]
        );
        $permissionService = app(SettingService::class);

        return $permissionService->UserAttachedToRoleList();
    }

    public function RolesRemoveAbility()
    {
        $req = request()->all();
        request()->validate([
            'id' => 'required',
            'pu' => 'required',
        ]);

        $this->UpdateOrCreateRecord('platform_users', ['role_id' => 0], ['id' => $req['pu']]);
        $permissionService = app(SettingService::class);

        return $permissionService->UserAttachedToRoleList();
    }
}
