<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Domain\Tenancy\Entities\Tenant;
use App\Http\Requests\Tenant\TenantSettingsFormRequest;
use App\Tenant\Services\TenantSettingService;
use Illuminate\View\View;

class TenantSettingsController extends TenantSettingService
{
    public function get_system_audit_log_list()
    {
        return $this->Response(['data' => self::systemAuditLogList()]);
    }
    public function create_capitalize()
    {
        return $this->Response(['data' => self::createCapitalize()]);
    }

    public function get_share_transaction_charge_list()
    {
        return $this->Response(['data' => self::shareTransactionChargeList()]);
    }

    public function create_share_transaction_charge()
    {
        return $this->Response(['data' => self::createShareTransactionCharge()]);
    }

    public function get_permissions_list()
    {
        return $this->Response(['data' => self::permissionsListCollection()]);
    }

    public function get_capitalize_log_list()
    {
        return $this->Response(['data' => self::capitalizeLogList()]);
    }

    public function get_capitalize_list()
    {
        return $this->Response(['data' => self::capitalizeList()]);
    }

    public function get_roles_list()
    {
        return $this->Response(['data' => self::RolesListCollection()]);
    }

    public function get_sms_config_list()
    {
        return $this->Response(['data' => self::smsConfigList()]);
    }

    public function get_notification_settings_list()
    {
        return $this->Response(['data' => self::notificationSettingsList()]);
    }

    public function get_share_setting_list()
    {
        return $this->Response(['data' => self::shareSettingList()]);
    }

    public function get_notification_list()
    {
        return $this->Response(['data' => self::notificationList()]);
    }

    public function get_permissions_details()
    {
        return $this->Response(['data' => self::permissionDetails()]);
    }

    public function get_roles_details()
    {
        return $this->Response(['data' => self::RolesDetails()]);
    }

    public function permissions_holders_list()
    {
        return $this->Response(['data' => self::permissionListHolders()]);
    }

    public function permissions_remove_ability()
    {
        return $this->Response(['data' => self::permissionRemoveAbility()]);
    }

    public function permissions_add_ability()
    {
        // return  $this->Response(['data' => SELF::permissionAddAbility()]);
    }

    public function permissions_drop_down()
    {
        return $this->Response(['data' => self::permissionsDropdown()]);
    }

    public function roles_create()
    {
        return $this->Response(['data' => self::RolesCreate()]);
    }

    public function roles_holders_list()
    {
        return $this->Response(['data' => self::UserAttachedToRoleList()]);
    }

    public function roles_add_ability()
    {
        return $this->Response(['data' => self::RolesAddAbility()]);
    }

    public function delete_role()
    {
        return $this->Response(['data' => self::deleteRole()]);
    }

    public function roles_remove_ability()
    {
        return $this->Response(['data' => self::RolesRemoveAbility()]);
    }

    public function save_changed_settings_share()
    {
        return $this->Response(['data' => self::saveChangedSettingsShare()]);
    }

    public function save_changed_settings()
    {
        return $this->Response(['data' => self::saveChangedSettings()]);
    }

    public function onboarding_settings_list()
    {
        return $this->Response(['data' => self::onboardingSettingsList()]);
    }

    public function savings_group_settings_list()
    {
        return $this->Response(['data' => self::savingsGroupSettingsList()]);
    }

    public function savings_settings_list()
    {
        return $this->Response(['data' => self::savingsSettingsList()]);
    }

    public function loan_settings_list()
    {
        return $this->Response(['data' => self::loanSettingsList()]);
    }

    public function get_branch_list()
    {
        return $this->Response(['data' => self::branchList()]);
    }

    public function get_branch_details()
    {
        return $this->Response(['data' => self::branchDetails()]);
    }

    public function create_branch()
    {
        return $this->Response(['data' => self::createBranch()]);
    }

    public function branches_drop_down_list()
    {
        return $this->Response(['data' => self::branchesDropDownList()]);
    }

    // public function roles_add_ability()
    // {
    //     return $this->Response(['data' => self::RolesAddAbility()]);
    // }

    // public function roles_remove_ability()
    // {
    //     return $this->Response(['data' => self::RolesRemoveAbility()]);
    // }

    // public function save_changed_settings()
    // {
    //     return $this->Response(['data' => self::saveChangedSettings()]);
    // }

    // public function onboarding_settings_list()
    // {
    //     return $this->Response(['data' => self::onboardingSettingsList()]);
    // }

    // public function get_branch_list()
    // {
    //     return $this->Response(['data' => self::branchList()]);
    // }

    // public function get_branch_details()
    // {
    //     return $this->Response(['data' => self::branchDetails()]);
    // }

    // public function create_branch()
    // {
    //     return $this->Response(['data' => self::createBranch()]);
    // }

    // public function branches_drop_down_list()
    // {
    //     return $this->Response(['data' => self::branchesDropDownList()]);
    // }

    /**
     * Show the tenant system settings form.
     */
    public function edit(): View
    {
        $tenant = $this->getCurrentTenant();

        return view('tenant-spa', [
            'settings' => $tenant->settings ?? [
                'logo_url' => '',
                'slogan' => '',
                'address' => '',
                'phone' => '',
                'email' => '',
            ],
        ]);
    }

    /**
     * Update the tenant system settings.
     */
    public function update(TenantSettingsFormRequest $request)
    {
        $validated = $request->validated();

        $tenant = $this->getCurrentTenant();
        $settings = $tenant->settings ?? [];

        // Handle Logo Upload
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('logos', 'public');
            $settings['logo_url'] = asset('storage/' . $path);
        }

        // Update other settings
        foreach (['slogan', 'address', 'phone', 'email'] as $field) {
            if ($request->has($field)) {
                $settings[$field] = $request->get($field);
            }
        }

        $tenant->settings = $settings;
        $tenant->save();

        return redirect()->back()->with('success', 'System settings updated successfully.');
    }

    /**
     * Helper to get the current tenant based on subdomain.
     */
    protected function getCurrentTenant(): Tenant
    {
        $host = request()->getHost();
        $subdomain = explode('.', $host)[0];

        return Tenant::where('subdomain', $subdomain)->firstOrFail();
    }
}
