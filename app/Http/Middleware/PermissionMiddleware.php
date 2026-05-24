<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, $permission = null)
    {
        $user = Auth::guard('sanctum')->user();

        if (! $permission) {
            return response()->json(['error' => "Target class [$permission] does not exist."], 400);
        }

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $userPermissions = DB::table('permissions_users')
            ->where('user_id', $user->id)
            ->value('permission_ids');

        $permissions = json_decode($userPermissions, true) ?? [];

        // No permissions configured → superadmin, allow everything
        if (empty($permissions)) {
            return $next($request);
        }

        $actionNames = DB::table('permissions')
            ->whereIn('id', $permissions)
            ->pluck('action')
            ->toArray();

        if (! in_array($permission, $actionNames)) {
            return response()->json([
                'error' => 'Forbidden',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
