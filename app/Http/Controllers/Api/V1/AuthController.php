<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PlatformUser;
use App\Models\Staff;
use App\Services\Authservice;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Authservice
{
    /**
     * Handle the login request.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'type' => 'required|in:central,tenant',
        ]);

        $user = null;

        if ($request->type === 'central') {
            $user = PlatformUser::where('email', $request->email)->first();
        } else {
            // Tenant identification is handled by the IdentifyTenant middleware automatically
            // which sets the database connection based on the subdomain.
            $user = Staff::where('email', $request->email)->first();
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $tokenName = $request->type === 'central' ? 'central-token' : 'tenant-token';
        $token = $user->createToken($tokenName)->plainTextToken;

        $response = [
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
                'permissions' => $this->collectPermission($user->id, $user instanceof Staff && $user->is_tenant_admin),
                'branch_context' => $request->type === 'tenant' ? BranchContext::authContextFor($user) : null,
                // system_settings & sacco_branding are tenant-only tables; they don't exist
                // in the central DB, so only collect them when a tenant DB is in context.
                'Setting' => $request->type === 'tenant' ? $this->collectAllSystemSetting() : (object) [],
                'branding' => $request->type === 'tenant' ? $this->collectSystemBranding() : null,
            ],
        ];
        // Ensure super users are redirected to central dashboard
        if ($request->type === 'central') {
            $response['data']['redirect_url'] = '/central/dashboard';
        }

        return response()->json(base64_encode(json_encode($response)));
    }

    /**
     * Handle the registration request for Central users (Super Admins).
     */
    public function register(Request $request)
    {
        // Central registration specific logic
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:master.platform_users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $user = PlatformUser::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $token = $user->createToken('central-token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Registration successful',
                'data' => [
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'user' => $user,
                    'redirect_url' => '/central/dashboard',
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Registration Error: '.$e->getMessage());
            Log::error($e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Registration failed. Please Check logs.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle the logout request.
     */
    public function logout(Request $request)
    {
        $isTenant = app()->bound('currentTenant');
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
            'data' => [
                'redirect_url' => $isTenant ? '/tenant/login' : '/central/login',
            ],
        ]);
    }

    /**
     * Get the authenticated user.
     */
    public function user(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'message' => 'User profile retrieved successfully',
            'data' => [
                'user' => $user,
                'permissions' => $user ? $this->collectPermission($user->id, $user instanceof Staff && $user->is_tenant_admin) : [],
                'branch_context' => $user instanceof Staff ? BranchContext::authContextFor($user) : null,
            ],
        ]);
    }

    /**
     * Refresh the token (Dummy implementation for consistency with routes).
     */
    public function refresh(Request $request)
    {
        // Sanctum doesn't have built-in refresh, but we can issue a new one
        $user = $request->user();
        $token = $user->createToken('refresh-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Token refreshed',
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'permissions' => $this->collectPermission($user->id, $user instanceof Staff && $user->is_tenant_admin),
                'branch_context' => $user instanceof Staff ? BranchContext::authContextFor($user) : null,
            ],
        ]);
    }
}
