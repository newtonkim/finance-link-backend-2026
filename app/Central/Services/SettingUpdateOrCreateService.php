<?php

namespace App\Central\Services;

use App\Http\Globals\GlobalHelpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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

    public function currencySettingsUpdate()
    {
        return $this->TryCatch(function () {
            $allowedCodes = [
                'USD', 'EUR', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD', 'CNY', 'HKD', 'SGD', 'INR', 'MXN',
                'KES', 'UGX', 'TZS', 'RWF', 'ZAR', 'NGN', 'GHS', 'AED', 'SAR',
            ];

            $validated = request()->validate([
                'default_currency' => ['required', 'string', Rule::in($allowedCodes)],
                'enabled_currencies' => ['nullable', 'array'],
                'enabled_currencies.*' => ['string', Rule::in($allowedCodes)],
            ]);

            $enabled = collect($validated['enabled_currencies'] ?? [])
                ->push($validated['default_currency'])
                ->filter()
                ->unique()
                ->values()
                ->all();

            DB::connection('master')->table('central_currency_settings')
                ->updateOrInsert(
                    ['id' => 1],
                    [
                        'default_currency' => $validated['default_currency'],
                        'enabled_currencies' => json_encode($enabled),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

            return app(SettingService::class)->currencySettings();
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
        return $this->TryCatch(function () {
            request()->validate([
                'name' => 'required|string|max:100',
                'key'  => 'required|string|max:60',
            ]);

            $key  = \Illuminate\Support\Str::snake(strtolower(trim(request('key'))));
            $name = trim(request('name'));

            $exists = DB::connection('master')
                ->table('plan_features')
                ->where('key', $key)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'key' => ['A feature with this key already exists.'],
                ]);
            }

            DB::connection('master')->table('plan_features')->insert([
                'name'       => $name,
                'key'        => $key,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return app(SettingService::class)->featuresList();
        });
    }

    public function featuresUpdate()
    {
        return $this->TryCatch(function () {
            request()->validate([
                'id'   => 'required|integer|exists:master.plan_features,id',
                'name' => 'required|string|max:100',
                'key'  => 'required|string|max:60',
            ]);

            $id = (int) request('id');
            $key = \Illuminate\Support\Str::snake(strtolower(trim(request('key'))));
            $name = trim(request('name'));

            $exists = DB::connection('master')
                ->table('plan_features')
                ->where('key', $key)
                ->where('id', '!=', $id)
                ->whereNull('deleted_at')
                ->exists();

            if ($exists) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'key' => ['A feature with this key already exists.'],
                ]);
            }

            DB::connection('master')->transaction(function () use ($id, $name, $key): void {
                $feature = DB::connection('master')
                    ->table('plan_features')
                    ->where('id', $id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                abort_if(! $feature, 404, 'Feature not found.');

                DB::connection('master')->table('plan_features')
                    ->where('id', $id)
                    ->update([
                        'name' => $name,
                        'key' => $key,
                        'updated_at' => now(),
                    ]);

                if ($feature->key !== $key) {
                    DB::connection('master')
                        ->table('plans')
                        ->whereNotNull('features')
                        ->orderBy('id')
                        ->chunkById(100, function ($plans) use ($feature, $key): void {
                            foreach ($plans as $plan) {
                                $features = is_string($plan->features)
                                    ? json_decode($plan->features, true)
                                    : $plan->features;

                                if (! is_array($features) || ! array_key_exists($feature->key, $features)) {
                                    continue;
                                }

                                $features[$key] = $features[$feature->key];
                                unset($features[$feature->key]);

                                DB::connection('master')
                                    ->table('plans')
                                    ->where('id', $plan->id)
                                    ->update([
                                        'features' => json_encode($features),
                                        'updated_at' => now(),
                                    ]);
                            }
                        });
                }
            });

            return app(SettingService::class)->featuresList();
        });
    }

    public function featuresDelete()
    {
        return $this->TryCatch(function () {
            request()->validate(['id' => 'required|integer']);

            DB::connection('master')->table('plan_features')
                ->where('id', request('id'))
                ->update(['deleted_at' => now()]);

            return app(SettingService::class)->featuresList();
        });
    }

    public function plansDelete()
    {
        $this->DeleteRecord('plans', request());
        $Service = app(SettingService::class);

        return $Service->plansList();
    }

    public function plansCreate()
    {
        $req = request()->all();
        request()->validate([
            'cost'         => 'required|numeric|min:0',
            'billing_type' => 'required',
            'mx_mbrs'      => 'required|numeric|min:0',
            'mxusrs'       => 'required|numeric|min:0',
            'name'         => 'required|string',
            'features'     => 'nullable',
        ]);

        $allFeatureKeys = DB::connection('master')
            ->table('plan_features')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->pluck('key')
            ->toArray();

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
