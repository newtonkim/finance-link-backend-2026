<?php

namespace App\Http\Middleware;

use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureMemberApiUser
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
            ?: Auth::guard('member')->user();

        if (! $user instanceof Member) {
            return response()->json(['message' => 'Member authentication is required.'], 403);
        }

        if ($user->status !== 'active') {
            return response()->json(['message' => 'Member account is not active.'], 403);
        }

        return $next($request);
    }
}
