<?php

namespace App\Tenant\Services;

use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;

class TenantStaffService extends TenantStaffUpdateOrCreateService
{
    protected array $staffDbFields = [
        'stf.id AS id',
        'stf.code AS code',
        'stf.name AS staff_fall_name',
        'stf.email AS staff_email',
        'stf.email_verified_at AS email_verified_time',
        'stf.role_id AS system_role_id',
        'rl.name AS system_role',
        'stf.branch_id AS branch_id',
        'stf.is_tenant_admin AS is_tenant_admin',
        'stf.status AS status',
        'stf.is_loan_officer AS is_loan_officer',
        'stf.can_vote_on_loans AS can_vote_on_loans',
        'stf.can_manage_branch AS can_manage_branch',
        'stf.can_finalise_loan AS can_finalise_loan',
        'stf.avatar AS avatar',
        'stf.created_at AS created_at',
    ];

    public function staffEditDetail()
    {
        request()->validate([
            'id' => 'required|numeric|exists:staff,id',
            'staff_email' => 'nullable|email|exists:staff,email',
        ]);
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $tenant = DB::table('staff AS stf')
                ->join('roles as rl', 'rl.id', '=', 'stf.role_id')
                ->leftJOIN('permissions_users as pu', 'pu.user_id', '=', 'stf.id')
                ->where('stf.id', $req->id)
                ->orWhere('stf.email', $req->staff_email)
                ->first([
                    ...$this->staffDbFields,
                    'pu.permission_ids',
                ]);

            return $tenant;
        });
    }

    public function staffDetails()
    {
        request()->validate([
            'id' => 'required|numeric|exists:staff,id',
            'staff_email' => 'nullable|email|exists:staff,email',
        ]);
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $tenant = DB::table('staff AS stf')
                ->join('roles as rl', 'rl.id', '=', 'stf.role_id')
                ->leftJOIN('permissions_users as pu', 'pu.user_id', '=', 'stf.id')
                ->where('stf.id', $req->id)
                ->orWhere('stf.email', $req->staff_email)
                ->first([
                    ...$this->staffDbFields,
                    'pu.permission_ids',
                ]);

            if ($tenant->avatar) {
                $tenant->avatar = asset('storage/'.$tenant->avatar);
            }

            if ($tenant->permission_ids) {
                $permission = DB::table('permissions')->whereIn('id', $this->isJSONToArray($tenant->permission_ids))->get(['id', 'action as name', 'parent_module as module', 'description as description']);
                $tenant->permissions = $permission;
            }
            $tenant->total_members_onboarded = DB::table('members')->where('referred_by', $req->id)->count('id');

            return $tenant;
        });
    }

    public function staffDropdownCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('staff as stf')
                ->select(['stf.id AS id', 'stf.name AS name'])
                ->where('stf.status', 'active')
                ->where('stf.is_loan_officer', true)
                ->whereNull('stf.deleted_at');

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query(
                    $query,
                    $req->search_keyword,
                    ['stf.id AS id', 'stf.name AS name']
                );
            }

            $user = BranchContext::getStaff();
            if ($user && BranchContext::scopeFor($user) !== BranchContext::SCOPE_ALL) {
                $allowedIds = BranchContext::allowedBranchIds();
                if (empty($allowedIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('stf.branch_id', $allowedIds);
                }
            } elseif (! empty($req->branch_id)) {
                $query->where('stf.branch_id', $req->branch_id);
            }

            return $query
                ->orderBy('stf.name')
                ->paginate($this->perpage());
        });
    }

    public function rolesDropdown()
    {
        return $this->dropDownList('roles');
    }

    public function downloadStaffImportTemplate()
    {
        return [
            'email' => 'Email',
            'name' => 'name',
            'password' => 'password',
            'role' => 'role code',
            'status' => 'status',
        ];
    }

    public function staffListCollection()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $staus = isset($req['status']) && $req['status'] != 'all' ? [$req['status']] : null;
            $query = DB::table('staff as stf')
                ->join('roles as rl', 'rl.id', '=', 'stf.role_id')
                ->select($this->staffDbFields);

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], $this->staffDbFields);
            }
            if (! empty($staus)) {
                $query = $query->whereIn('stf.status', $staus);
            }

            $user = BranchContext::getStaff();
            if ($user && BranchContext::scopeFor($user) !== BranchContext::SCOPE_ALL) {
                $allowedIds = BranchContext::allowedBranchIds();
                if (empty($allowedIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('stf.branch_id', $allowedIds);
                }
            } elseif (! empty($req->branch_id)) {
                $query->where('stf.branch_id', $req->branch_id);
            }

            $staff = $query
                ->whereNull('stf.deleted_at')
                ->orderBy('stf.created_at', 'DESC')->paginate($this->perpage());

            $staff->getCollection()->transform(function ($item) {
                if ($item->avatar) {
                    $item->avatar = asset('storage/'.$item->avatar);
                }
                return $item;
            });

            return $staff;
        });
    }
}
