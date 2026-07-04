<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffApiUser
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()
            ?: Auth::guard('sanctum')->user()
            ?: Auth::guard('tenant')->user();

        if (! $user instanceof Staff) {
            return response()->json(['message' => 'Staff authentication is required.'], 403);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Staff account is not active.'], 403);
        }

        return $next($request);
    }
}
