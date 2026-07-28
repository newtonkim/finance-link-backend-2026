<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Http\Resources\MemberResource;
use App\Tenant\Http\Resources\StaffResource;
use App\Tenant\Services\TenantStaffService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TenantStaffController extends TenantStaffService
{
    public function get_staff_list()
    {
        return $this->Response(['data' => self::staffListCollection()]);
    }

    public function users_drop_down()
    {
        return $this->Response(['data' => self::staffDropdownCollection()]);
    }

    public function roles_drop_down()
    {
        return $this->Response(['data' => self::rolesDropdown()]);
    }

    public function staff_create()
    {
        return $this->Response(['data' => self::staffNewRecord()]);
    }

    public function edit_staff_details()
    {
        return $this->Response(['data' => self::staffEditDetail()]);
    }

    public function get_staff_details()
    {
        return $this->Response(['data' => self::staffDetails()]);
    }

    public function delete_staff()
    {
        return $this->Response(['data' => self::staffDelete()]);
    }

    public function download_staff_import_template()
    {
        return $this->Response(['data' => self::downloadStaffImportTemplate()]);
    }

    public function index()
    {
        $staff = Staff::orderBy('name')->get();

        return StaffResource::collection($staff);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique(Staff::class, 'email'),
            ],
            'password' => 'required|string|min:8',
            'branch_id' => 'nullable|integer|exists:tenant.branches,id',
            'role' => 'sometimes|string',
            'role_id' => 'required|integer|exists:tenant.roles,id',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'is_tenant_admin' => 'sometimes|boolean',
            'is_loan_officer' => 'sometimes|boolean',
            'can_vote_on_loans' => 'sometimes|boolean',
            'can_manage_branch' => 'sometimes|boolean',
            'can_finalise_loan' => 'sometimes|boolean',
        ]);

        $role = DB::connection('tenant')
            ->table('roles')
            ->where('id', $validated['role_id'])
            ->first(['name', 'default_permissions']);
        $validated['role'] = $role->name;
        $validated['password'] = Hash::make($validated['password']);

        $staff = DB::connection('tenant')->transaction(function () use ($validated, $role) {
            $staff = Staff::create($validated);
            $defaultPermissions = json_decode($role->default_permissions ?? '[]', true);

            if (is_array($defaultPermissions) && $defaultPermissions !== []) {
                DB::connection('tenant')->table('permissions_users')->updateOrInsert(
                    ['user_id' => $staff->id],
                    [
                        'permission_ids' => json_encode(array_values($defaultPermissions)),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            return $staff;
        });

        return new StaffResource($staff);
    }

    public function show(Staff $staff)
    {
        return new StaffResource($staff);
    }

    public function update(Request $request, Staff $staff)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => [
                'sometimes',
                'email',
                'max:255',
                Rule::unique(Staff::class, 'email')->ignore($staff->id),
            ],
            'password' => 'sometimes|string|min:8',
            'branch_id' => 'nullable|integer|exists:tenant.branches,id',
            'role' => 'sometimes|string',
            'role_id' => 'sometimes|integer|exists:tenant.roles,id',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'is_tenant_admin' => 'sometimes|boolean',
            'is_loan_officer' => 'sometimes|boolean',
            'can_vote_on_loans' => 'sometimes|boolean',
            'can_manage_branch' => 'sometimes|boolean',
            'can_finalise_loan' => 'sometimes|boolean',
        ]);

        if (isset($validated['role_id'])) {
            $validated['role'] = DB::connection('tenant')
                ->table('roles')
                ->where('id', $validated['role_id'])
                ->value('name');
        }

        if (isset($validated['password']) && $validated['password']) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $staff->update($validated);

        return new StaffResource($staff);
    }

    public function destroy(Staff $staff)
    {
        $staff->delete();

        return response()->noContent();
    }

    public function referredMembers(Staff $staff)
    {
        $members = Member::where('referred_by', $staff->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return MemberResource::collection($members);
    }

    public function uploadAvatar(Request $request, Staff $staff)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048'], // 2MB Max
        ]);

        if (!$request->hasFile('avatar')) {
            return response()->json([
                'success' => false,
                'message' => 'No avatar file provided.',
            ], 400);
        }

        try {
            if ($staff->avatar) {
                Storage::disk('public')->delete($staff->avatar);
            }

            $path = $request->file('avatar')->store('avatars', 'public');
            $staff->update(['avatar' => $path]);

            return response()->json([
                'success' => true,
                'message' => 'Avatar updated successfully.',
                'avatar_url' => $staff->avatar_url,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Avatar upload failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update avatar.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
