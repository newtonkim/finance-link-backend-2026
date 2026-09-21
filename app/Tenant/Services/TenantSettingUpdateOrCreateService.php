<?php

namespace App\Tenant\Services;

use App\Http\Globals\GlobalHelpers;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use App\Tenant\Services\TenantSavingsAcountServices\OtherHelpers;
use Illuminate\Support\Facades\DB;

class TenantSettingUpdateOrCreateService extends GlobalHelpers
{
    protected function capitalizeUOrCFields($req)
    {
        return $this->removeAllNullValues(
            [
                'code' => $req['code'] ?? null,
                'open_capital' => $req['capital'] ?? null,
                'share_price' => $req['share_price'] ?? null,
                'open_capital_points_walth_amount' => $req['points_value'] ?? null,
                'status' => $req['status'] ?? null,
                'opening_balance' => $req['balance'] ?? null,
                'branch_id' => $req['branch_id' ?? null],
            ]
        );
    }

    protected function branchUOrCFields($req)
    {
        return $this->removeAllNullValues(
            [
                'code' => $req['code'] ?? null,
                'name' => $req['branch_name'] ?? null,
                'phone' => $req['branch_phone'] ?? null,
                'email' => $req['branch_email'] ?? null,
                'address' => $req['branch_address'] ?? null,
                'is_active' => $req['is_active'] ?? null,
                'manager_id' => $req['in_charge' ?? null],
            ]
        );
    }

    protected function rolesUOrCFields($req)
    {
        return $this->removeAllNullValues(
            [
                'default_permissions' => ! empty($req['permission']) ? (array_values(array_map('intval', $req['permission']))) : null,
                'name' => $req['name'] ?? null,
                'description' => $req['dec'] ?? null,
                'branch_id' => $req['branch_id'] ?? null,
            ]
        );
    }

    protected function createShareTransactionChargeUOrCFields($req)
    {
        return $this->removeAllNullValues(
            [
                'code' => $req['code'] ?? null,
                'transaction_type' => $req['method'] ?? null,
                'minimum_shares' => $req['minimum_amount'] ?? null,
                'maximum_shares' => $req['maximum_amount'] ?? null,
                'amount' => $req['amount'] ?? null,
                'status' => $req['status'] ?? null,
                'charge_type' => $req['type'],
                'branch_id' => $req['branch_id'],
            ]
        );
    }

    public function createShareTransactionCharge()
    {
        $data = request()->validate([
            'rows' => 'required|array|min:1',
            'rows.*.type' => 'required|string',
            'rows.*.method' => 'required|string',
            'rows.*.minimum_amount' => 'required|numeric',
            'rows.*.maximum_amount' => 'required|numeric',
            'rows.*.amount' => 'required|numeric',
            'rows.*.status' => 'required|string|max:30',
            'rows.*.code' => 'nullable|string|max:30',
        ]);

        $rows = $data['rows'];
        foreach ($rows as $row) {
            $row['branch_id'] = request()->branch_id;
            if (empty($row['code'])) {
                $code = new CodeSequence;
                $row['code'] = $code->codeSequence();
            }
            $fieldV = $this->createShareTransactionChargeUOrCFields($row);
            $this->UpdateOrCreateRecord('share_transaction_charges', $fieldV);
        }

        $List = app(TenantSettingService::class);

        return $List->shareTransactionChargeList();
    }

    public function createBranch()
    {
        request()->validate([
            'branch_name' => 'required|string|max:30',
            'branch_phone' => 'nullable|string|max:30',
            'branch_email' => 'nullable|string|max:30',
            'branch_address' => 'nullable|string|max:100',
            'in_charge' => 'nullable|numeric|min:1',
            'is_active' => 'required',
        ]);
        $fieldV = $this->branchUOrCFields(request()->all());
        $req = request()->all();
        if (empty($req['id'])) {
            $code = new CodeSequence;

            $fieldV['code'] = $code->saccoMemberCodePrefix().$code->saccoMemberForceToGenerateOne();
        }
        $fieldV['is_active'] = $req['is_active'] == 'true' ? true : false;
        $this->UpdateOrCreateRecord('branches', $fieldV);
        $membersList = app(TenantSettingService::class);

        return $membersList->branchList();
    }

    public function createCapitalize()
    {
        request()->validate([
            'code' => 'nullable|string|max:30',
            'capital' => 'required|string|max:30',
            'share_price' => 'required|string|max:30',
            'points_value' => 'nullable|string|max:30',
            'status' => 'required',
            'branch_id' => 'required',
        ]);

        $req = request()->all();
        $AnyAvaliable = DB::table('share_capitalization')->orderBy('id', 'desc')->first();

        $fieldV = $this->capitalizeUOrCFields($req);

        $capital = $fieldV['open_capital'];
        $code = new CodeSequence;
        if (empty($req['id'])) {
            //  add ing
            $fieldV['open_capital'] = ($capital ?? 0) + $AnyAvaliable->open_capital;
            $fieldV['opening_balance'] = ($capital ?? 0) + $AnyAvaliable->opening_balance;
            $fieldV['code'] = $code->codeSequence();
        } else {
            $fieldV['opening_balance'] = $req['balance'];
        }

        $fieldV['open_capital_points_walth_amount'] = $fieldV['open_capital'] * $fieldV['share_price'];

        if (isset($req['status']) && $req['status'] == 'active') {
            $SharePrice = DB::table('system_settings')->where('settings_name', 'sacco-share-price-value')->first(['settings_action', 'id']);
            $priceNowForShare = json_decode($SharePrice->settings_action, true);
            $priceNowForShare['action'] = $fieldV['share_price'];
            DB::table('system_settings')->where('id', $SharePrice->id)->update(['settings_action' => ($priceNowForShare)]);
        }

        if (isset($AnyAvaliable->id)) {

            $savedData = $this->UpdateOrCreateRecord('share_capitalization', $fieldV, [
                'id' => $AnyAvaliable->id,
            ]);
        } else {
            $savedData = $this->UpdateOrCreateRecord('share_capitalization', $fieldV);
        }

        $fieldV = [
            ...$fieldV,
            'open_capital' => $AnyAvaliable->open_capital,
            'open_capital_points_walth_amount' => $AnyAvaliable->open_capital_points_walth_amount,
            'opening_balance' => $AnyAvaliable->opening_balance,
            'share_price' => $AnyAvaliable->share_price,
            'code' => $code->codeSequence(),
            'share_capitalization_id' => $savedData->id,
            'new_open_capital' => $fieldV['open_capital'],
            'new_opening_balance' => $fieldV['opening_balance'],
            'new_share_price' => $fieldV['share_price'],
            'other_data' => (array) $AnyAvaliable,
            'new_open_capital_points_walth_amount' => $fieldV['open_capital_points_walth_amount'],
        ];
        if (request()->has('id')) {
            request()->request->remove('id');
        }

        $this->UpdateOrCreateRecord('share_capitalization_history', $fieldV);
        $List = app(TenantSettingService::class);

        return $List->capitalizeList();
    }

    /** The setting whose value is the share price; only it drives capitalisation. */
    private const SHARE_PRICE_SETTING = 'sacco-share-price-value';

    public function saveChangedSettingsShare()
    {
        // this   is used in more places
        $req = request()->all();

        $this->recalculateCapitalisationIfSharePriceChanged($req);

        $this->UpdateOrCreateRecord('system_settings', [
            'id' => $req['id'],
            'settings_action' => $req['settings_action'],
        ]);
        $membersList = app(TenantSettingService::class);
        if (isset($req['from']) && in_array($req['settings_action'], ['saving-account'])) {
            return $membersList->savingsSettingsList();
        }

        return $membersList->onboardingSettingsList();
    }

    /**
     * Keep share capitalisation in step with the share price.
     *
     * Two things used to go wrong here. The guard asked whether the share-price
     * setting *existed* rather than whether *this request* was saving it, so every
     * share setting entered this branch — saving the hide-shareholder switch fed its
     * own 1 or 0 in as the share price. And the latest capitalisation row was
     * dereferenced without checking one was returned, so on any tenant with no
     * capitalisation rows — which is every newly provisioned tenant — the save threw
     * and the setting was never written at all.
     */
    private function recalculateCapitalisationIfSharePriceChanged(array $req): void
    {
        $setting = DB::table('system_settings')
            ->where('id', $req['id'] ?? null)
            ->first(['settings_name']);

        if (! $setting || $setting->settings_name !== self::SHARE_PRICE_SETTING) {
            return;
        }

        $sharePrice = (float) ($req['settings_action']['action'] ?? 0);

        $capital = DB::table('share_capitalization')
            ->orderBy('id', 'desc')
            ->first(['id', 'open_capital']);

        // No capitalisation opened yet; the price is still recorded on the setting
        // and will be picked up when one is created.
        if (! $capital) {
            return;
        }

        DB::table('share_capitalization')->where('id', $capital->id)->update([
            'open_capital_points_walth_amount' => $capital->open_capital * $sharePrice,
            'share_price' => $sharePrice,
        ]);
    }

    public function saveChangedSettings()
    {
        // this   is used in more places
        $req = request()->all();
        $this->UpdateOrCreateRecord('system_settings', [
            'id' => $req['id'],
            'settings_action' => $req['settings_action'],
        ]);
        // $membersList = app(TenantSettingService::class);
        // if (isset($req['from']) && in_array($req['settings_action'], ['saving-account'])) {
        //     return $membersList->savingsSettingsList();
        // }

        // return $membersList->onboardingSettingsList();
        $otherHelpers = new OtherHelpers;

        return $otherHelpers->SettingsListPreparation([], []);
    }

    public function RolesCreate()
    {
        return $this->TryCatch(function () {
            $req = request()->all();
            $nameCheck = DB::table('roles')->where('name', $req['name'])->first();
            if ($nameCheck) {
                throw new \Exception('Role name already exists');
            }
            $field = $this->rolesUOrCFields($req);
            $code = new CodeSequence;

            if (empty($req['id'])) {
                $code = new CodeSequence;
                $field['code'] = $code->codeSequence();
            }
            $this->UpdateOrCreateRecord('roles', $field);
            $role = app(TenantSettingService::class);

            return $role->RolesListCollection();
        });
    }

    public function deleteRole()
    {
        $req = request()->all();
        request()->validate([
            'id' => 'required',
        ]);
        $this->DeleteRecord('roles', ['id' => $req['id']]);
        $role = app(TenantSettingService::class);

        return $role->RolesListCollection();
    }

    public function RolesAddAbility()
    {
        return $this->transaction(function () {
            $req = request()->all();
            request()->validate([
                'id' => 'required',
                'pu' => 'required',
            ]);
            $this->UpdateOrCreateRecord(
                'staff',
                ['role_id' => $req['id']],
                ['id' => $req['pu']]
            );
            if ($req['reset']) {
                $roles = DB::table('roles')->where('id', $req['id'])->first(['default_permissions']);
                $permissions = $this->isJSONToArray(json_decode($roles->default_permissions ?? '[]', true));
                $this->UpdateOrCreateRecord('permissions_users', ['permission_ids' => $permissions], ['user_id' => $req['pu']]);
            }
            $role = app(TenantSettingService::class);

            return $role->UserAttachedToRoleList();
        });
    }

    public function RolesRemoveAbility()
    {
        $req = request()->all();
        request()->validate([
            'id' => 'required',
            'pu' => 'required',
        ]);

        $this->UpdateOrCreateRecord('staff', ['role_id' => 0], ['id' => $req['pu']]);
        $role = app(TenantSettingService::class);

        return $role->UserAttachedToRoleList();
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

            $permissionService = app(TenantSettingService::class);

            return $permissionService->permissionListHolders();
        });
    }
}
