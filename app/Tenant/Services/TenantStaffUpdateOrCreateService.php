<?php

namespace App\Tenant\Services;

use App\Http\Globals\GlobalHelpers;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TenantStaffUpdateOrCreateService extends GlobalHelpers
{
    protected function staffUOrCFields($req)
    {
        $branchId = $req['branch_id'] ?? null;

        if (empty($branchId)) {
            $branchId = DB::connection('tenant')
                ->table('branches')
                ->orderBy('id')
                ->value('id') ?? 1;
        }

        $roleId = $req['role_id'] ?? $req['system_role'] ?? null;
        if (is_array($roleId) && isset($roleId['id'])) {
            $roleId = $roleId['id'];
        }

        return $this->removeAllNullValues(
            [
                'name' => $req['staff_fall_name'] ?? null,
                'email' => $req['staff_email'] ?? null,
                'email_verified_at' => $req['email_verified_at'] ?? null,
                'role_id' => $roleId,
                'branch_id' => $branchId,
                'status' => $req['status'] ?? null,
                'is_tenant_admin' => $req['is_tenant_admin'] ?? null,
                'is_loan_officer' => $req['is_loan_officer'] ?? null,
                'can_vote_on_loans' => $req['can_vote_on_loans'] ?? null,
                'can_manage_branch' => $req['can_manage_branch'] ?? null,
                'can_finalise_loan' => $req['can_finalise_loan'] ?? null,
            ]
        );
    }

    public function staffDelete()
    {
        $this->DeleteRecord('staff', request());
        $TenantStaffService = app(TenantStaffService::class);

        return $TenantStaffService->staffListCollection();
    }

    public function staffNewRecord()
    {
        return $this->transaction(function () {
            $codeSequence = new CodeSequence;
            $req = request()->all();
            $dataField = $this->staffUOrCFields($req);
            $code = $codeSequence->codeSequence($req['code'] ?? null, 'staff', 'staff-on-boarding', 'staff');
            $dataField['code'] = $code;

            $dataField['password'] = Hash::make($req['password']);
            $dataField['created_at'] = now();
            $userDetails = $this->UpdateOrCreateRecord('staff', $dataField);
            $roleId = $req['role_id'] ?? $req['system_role'] ?? null;
            if (is_array($roleId) && isset($roleId['id'])) {
                $roleId = $roleId['id'];
            }

            if (! empty($roleId)) {
                $roleDefaultPermission = DB::table('roles')->where('id', $roleId)->first(['default_permissions']);
                $permissions = $this->isJSONToArray(json_decode($roleDefaultPermission->default_permissions ?? '[]', true));
                if (! empty($permissions)) {
                    $this->UpdateOrCreateRecord('permissions_users', [
                        'user_id' => $userDetails->id,
                        'permission_ids' => $permissions,
                    ]);
                }
            } else {
                throw new \Exception('A system role is required to create a staff member.');
            }
            $TenantStaffService = app(TenantStaffService::class);

            return $TenantStaffService->staffListCollection();
        });
    }
}
