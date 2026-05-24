<?php

namespace App\Tenant\Services;

class TenantMemeberSettings extends TenantSettingUpdateOrCreateService
{
    public function memberSettingsList()
    {
        return $this->collectSettings();
    }

    public function checkIfRequiresApproval()
    {
        return $datacolecetion['sacco-members-Require-approval-before-members-becomes-active']['action'] ?? false;
    }
}
