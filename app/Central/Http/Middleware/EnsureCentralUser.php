<?php

namespace App\Central\Http\Middleware;

use App\Models\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCentralUser
{
    /**
     * Ensure central API requests are made by authenticated platform users only.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('sanctum')->user() ?: Auth::guard('platform')->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user instanceof PlatformUser) {
            return response()->json(['message' => 'Forbidden. Central access is required.'], 403);
        }

        return $next($request);
    }
}
