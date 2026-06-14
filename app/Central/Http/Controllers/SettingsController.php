<?php

namespace App\Central\Http\Controllers;

use App\Central\Services\SettingService;
use Illuminate\Http\Request;

class SettingsController extends SettingService
{
    /**
     * Display a listing of permissions.
     */
    public function index()
    {
        return $this->permissionsListCollection();
    }

    public function get_permissions_list()
    {
        return $this->Response(['data' => $this->permissionsListCollection()]);
    }

    public function get_roles_list()
    {
        return $this->Response(['data' => $this->RolesListCollection()]);
    }

    public function get_permissions_details()
    {
        return $this->Response(['data' => $this->permissionDetails()]);
    }

    public function permissions_holders_list()
    {
        return $this->Response(['data' => $this->permissionListHolders()]);
    }

    public function permissions_remove_ability()
    {
        return $this->Response(['data' => $this->permissionRemoveAbility()]);
    }

    public function permissions_add_ability()
    {
        return $this->Response(['data' => $this->permissionAddAbility()]);
    }

    public function permissions_drop_down()
    {
        return $this->Response(['data' => $this->permissionsDropdown()]);
    }

    public function roles_create()
    {
        return $this->Response(['data' => $this->RolesCreate()]);
    }

    public function roles_holders_list()
    {
        return $this->Response(['data' => $this->UserAttachedToRoleList()]);
    }

    public function roles_add_ability()
    {
        return $this->Response(['data' => $this->RolesAddAbility()]);
    }

    public function roles_remove_ability()
    {
        return $this->Response(['data' => $this->RolesRemoveAbility()]);
    }

    public function get_features_list()
    {
        return $this->Response(['data' => $this->featuresList()]);
    }

    public function features_create()
    {
        return $this->Response(['data' => $this->featuresCreate()]);
    }

    public function features_update()
    {
        return $this->Response(['data' => $this->featuresUpdate()]);
    }

    public function features_delete()
    {
        return $this->Response(['data' => $this->featuresDelete()]);
    }

    public function get_plans_list()
    {
        return $this->Response(['data' => $this->plansList()]);
    }

    public function get_plans_stats()
    {
        return $this->Response(['data' => $this->plansStats()]);
    }

    public function plans_create()
    {
        return $this->Response(['data' => $this->plansCreate()]);
    }

    public function get_plans_details()
    {
        return $this->Response(['data' => $this->plansDetails()]);
    }

    public function plans_delete()
    {
        return $this->Response(['data' => $this->plansDelete()]);
    }

    public function plans_drop_down()
    {
        return $this->Response(['data' => $this->plansDropDown()]);
    }

    public function get_branding()
    {
        return $this->Response(['data' => $this->brandingDetails()]);
    }

    public function update_branding()
    {
        return $this->Response(['data' => $this->brandingUpdate()]);
    }

    public function get_currency_settings()
    {
        return $this->Response(['data' => $this->currencySettings()]);
    }

    public function update_currency_settings()
    {
        return $this->Response(['data' => $this->currencySettingsUpdate()]);
    }

    public function store(Request $request) {}
}
