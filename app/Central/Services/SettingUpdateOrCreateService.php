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
        $name = $req['name'] ?? null;
        $slug = $name ? \Illuminate\Support\Str::slug($name) : null;

        return $this->removeAllNullValues(
            [
                'price'         => $req['cost'] ?? null,
                'billing_cycle' => $req['billing_type'] ?? null,
                'max_members'   => isset($req['mx_mbrs']) ? (int) $req['mx_mbrs'] : null,
                'max_users'     => isset($req['mxusrs'])  ? (int) $req['mxusrs']  : null,
                'features'      => $req['features'] ?? null,
                'slug'          => $slug,
                'name'          => $name,
                'days'          => $req['days'] ?? null,
            ]
        );
    }

    public function featuresCreate()
    {
        $req = request()->all();
        request()->validate([
            'name' => 'required|string|max:100',
            'key'  => 'required|string|max:60',
        ]);

        $fields = [
            'name'      => trim($req['name']),
            'key'       => \Illuminate\Support\Str::snake(strtolower(trim($req['key']))),
            'is_active' => true,
        ];

        $this->UpdateOrCreateRecord('plan_features', $fields);

        $service = new SettingService;

        return $service->featuresList();
    }

    public function featuresDelete()
    {
        $this->DeleteRecord('plan_features', request());

        $service = new SettingService;

        return $service->featuresList();
    }

    public function plansDelete()
    {
        $this->DeleteRecord('plans', request());
        $Service = app(SettingService::class);

        return $Service->plansList();
    }

    public function plansCreate()
    {
        $allFeatureKeys = DB::connection('master')
            ->table('plan_features')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->pluck('key')
            ->toArray();

        $req = request()->all();
        request()->validate([
            'cost'         => 'required|numeric|min:0',
            'billing_type' => 'required',
            'mx_mbrs'      => 'required|numeric|min:0',
            'mxusrs'       => 'required|numeric|min:0',
            'name'         => 'required|string',
            'features'     => 'nullable',
        ]);

        $fields = $this->plansUOrCFields($req);

        // Build a boolean features map from the submitted array of IDs
        $selectedIds = array_map(
            fn ($t) => trim((string) $t, '"'),
            (array) ($req['features'] ?? [])
        );
        $featuresMap = [];
        foreach ($allFeatureKeys as $key) {
            $featuresMap[$key] = in_array($key, $selectedIds);
        }
        $fields['features'] = $featuresMap;
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
