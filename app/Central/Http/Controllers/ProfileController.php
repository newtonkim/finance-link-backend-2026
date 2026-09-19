<?php

namespace App\Central\Http\Controllers;

use App\Http\Globals\BaseController;
use App\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Self-service profile for the signed-in central (platform) user.
 *
 * The frontend names these fields differently from the platform_users columns,
 * so the payload is mapped explicitly rather than returning the model raw:
 *
 *   staff_fall_name      -> name
 *   staff_email          -> email
 *   system_role          -> role_id
 *   email_verified_time  -> email_verified_at
 */
class ProfileController extends BaseController
{
    public function show(Request $request)
    {
        return $this->Response(['data' => $this->present($this->currentUser($request))]);
    }

    public function update(Request $request)
    {
        $user = $this->currentUser($request);

        $validated = $request->validate([
            'staff_fall_name' => ['sometimes', 'string', 'max:255'],
            'staff_email' => [
                'sometimes', 'email', 'max:255',
                Rule::unique('platform_users', 'email')->ignore($user->id),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
            'avatar' => ['sometimes', 'nullable', 'image', 'max:5120'],
        ]);

        // Role is deliberately not self-editable: a lower-privileged central user
        // could otherwise promote themselves. Role changes belong to staff
        // management, which is permission-gated.
        $submittedRole = $request->input('system_role');
        if ($submittedRole !== null && $submittedRole !== '' && $submittedRole !== $user->role_id) {
            throw ValidationException::withMessages([
                'system_role' => ['Your role cannot be changed from the profile page. Use staff management.'],
            ]);
        }

        if (array_key_exists('staff_fall_name', $validated)) {
            $user->name = $validated['staff_fall_name'];
        }

        if (array_key_exists('staff_email', $validated)) {
            // Re-verification is required when the address changes.
            if ($validated['staff_email'] !== $user->email) {
                $user->email_verified_at = null;
            }
            $user->email = $validated['staff_email'];
        }

        if (! empty($validated['password'])) {
            // 'password' is cast to 'hashed' on the model, so assign the plain value.
            $user->password = $validated['password'];
        }

        if ($request->hasFile('avatar')) {
            if ($user->avatar) {
                Storage::disk('public')->delete($user->avatar);
            }
            $user->avatar = $request->file('avatar')->store('avatars', 'public');
        }

        $user->save();

        return $this->Response([
            'data' => $this->present($user->fresh()),
            'msg' => 'Profile updated successfully',
        ]);
    }

    public function destroy(Request $request)
    {
        $user = $this->currentUser($request);

        // Refuse to remove the last account that can still administer the platform.
        $remaining = PlatformUser::on('master')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('id', '!=', $user->id)
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'account' => ['This is the last active platform account and cannot be deleted.'],
            ]);
        }

        // platform_users carries deleted_at but the model does not use SoftDeletes,
        // so the soft delete is applied directly.
        DB::connection('master')->table('platform_users')
            ->where('id', $user->id)
            ->update(['deleted_at' => now(), 'status' => 'deleted']);

        $user->tokens()->delete();

        return $this->Response([
            'data' => ['redirect_url' => '/login'],
            'msg' => 'Account deleted',
        ]);
    }

    /**
     * The central middleware binds the authenticated platform user to the request.
     * If that ever stops happening, fail with a clear 401 rather than a null
     * property access deeper in the method.
     */
    private function currentUser(Request $request): PlatformUser
    {
        $user = $request->user();

        if (! $user instanceof PlatformUser) {
            abort(401, 'Unauthenticated.');
        }

        return $user;
    }

    /** Map a platform user onto the shape the central frontend expects. */
    private function present(PlatformUser $user): array
    {
        return [
            'id' => $user->id,
            'staff_fall_name' => $user->name,
            'staff_email' => $user->email,
            'system_role' => $user->role_id,
            'status' => $user->status,
            'avatar' => $user->avatar,
            'avatar_url' => $user->avatar ? Storage::disk('public')->url($user->avatar) : null,
            'email_verified_time' => optional($user->email_verified_at)->toDateTimeString(),
            'created_at' => optional($user->created_at)->toDateTimeString(),
        ];
    }
}
