<?php

namespace App\Central\Http\Controllers;

use App\Http\Globals\GlobalHelpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Self-service profile management for the logged-in central (platform) user.
 * Lives under the authenticated `v1/central` group so a user can always view
 * and manage their own profile without needing staff-management permissions.
 */
class CentralProfileController extends GlobalHelpers
{
    protected array $fields = [
        'id',
        'name AS staff_fall_name',
        'email AS staff_email',
        'avatar',
        'role_id AS system_role',
        'status',
        'email_verified_at AS email_verified_time',
        'created_at',
    ];

    /** GET /v1/central/profile */
    public function show()
    {
        return $this->Response(['data' => $this->profileRow(auth()->id())]);
    }

    /** POST /v1/central/profile/update */
    public function update(Request $request)
    {
        $id = auth()->id();

        $request->validate([
            'staff_fall_name' => 'nullable|string|max:255',
            'staff_email' => ['nullable', 'email', Rule::unique('master.platform_users', 'email')->ignore($id)],
            'password' => 'nullable|string|min:8',
            'system_role' => 'nullable|string|max:255',
            'avatar' => 'nullable|image|max:2048',
        ]);

        return $this->TryCatch(function () use ($request, $id) {
            $fields = $this->removeAllNullValues([
                'name' => $request->staff_fall_name,
                'email' => $request->staff_email,
                'role_id' => $request->system_role,
            ]);

            if ($request->filled('password')) {
                $fields['password'] = Hash::make($request->password);
            }

            if ($request->hasFile('avatar')) {
                $current = DB::connection('master')->table('platform_users')->where('id', $id)->value('avatar');
                if ($current) {
                    Storage::disk('public')->delete($current);
                }
                $fields['avatar'] = $request->file('avatar')->store('platform-avatars', 'public');
            }

            $fields['updated_at'] = now();

            DB::connection('master')->table('platform_users')->where('id', $id)->update($fields);

            return $this->Response([
                'data' => $this->profileRow($id),
                'msg' => 'Profile updated successfully',
            ]);
        });
    }

    /** POST /v1/central/profile/delete */
    public function destroy(Request $request)
    {
        $user = auth()->user();

        return $this->TryCatch(function () use ($user) {
            // Soft-delete and deactivate the account.
            DB::connection('master')->table('platform_users')
                ->where('id', $user->id)
                ->update(['deleted_at' => now(), 'status' => 'suspended']);

            // Revoke all tokens so the session ends immediately.
            $user->tokens()->delete();

            return $this->Response([
                'data' => ['redirect_url' => '/central/login'],
                'msg' => 'Account deleted successfully',
            ]);
        });
    }

    private function profileRow($id)
    {
        return $this->TryCatch(function () use ($id) {
            $row = DB::connection('master')->table('platform_users')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first($this->fields);

            if ($row) {
                $row->avatar_url = $row->avatar ? '/storage/'.$row->avatar : null;
            }

            return $row;
        });
    }
}
