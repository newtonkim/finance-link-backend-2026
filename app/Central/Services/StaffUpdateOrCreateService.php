<?php

namespace App\Central\Services;

use App\Http\Globals\GlobalHelpers;
use Illuminate\Support\Facades\Hash;

class StaffUpdateOrCreateService extends GlobalHelpers
{
    protected function staffUOrCFields($req)
    {
        return $this->removeAllNullValues(
            [
                'name' => $req['staff_fall_name'] ?? null,
                'email' => $req['staff_email'] ?? null,
                'email_verified_at' => $req['email_verified_time'] ?? null,
                'role_id' => $req['system_role'] ?? null,
                'is_tenant_admin' => $req['is_tenant_admin'] ?? null,
                'status' => $req['status'] ?? null,
            ]);
    }

    public function staffDelete()
    {
        $this->DeleteRecord('platform_users', request());
        $staffService = app(StaffService::class);

        return $staffService->staffListCollection();
    }

    public function staffNewRecord()
    {
        $req = request()->all();
        $rr = $this->staffUOrCFields($req);
        $rr['password'] = Hash::make($req['password']);
        $rr['created_at'] = now();
        $this->UpdateOrCreateRecord('platform_users', $rr);
        $staffService = app(StaffService::class);

        return $staffService->staffListCollection();

    }
}
