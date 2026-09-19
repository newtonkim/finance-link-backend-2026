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

        // Bind the resolved user to the request. The default guard is the
        // session-backed 'web' guard, so without this $request->user() returns null
        // for token-authenticated API calls and controllers reading it blow up with
        // "Attempt to read property on null".
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
