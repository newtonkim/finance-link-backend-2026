<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Globals\GlobalHelpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

if (! function_exists('routeList')) {
    function routeList(array $routes, string $module, $particular)
    {
        $fun = new GlobalHelpers;

        return $fun->routeList($routes, $module, $particular);
    }
}
if (! function_exists('routeListV2')) {
    function routeListV2(array $routes)
    {
        $fun = new GlobalHelpers;

        return $fun->routeListV2($routes);
    }
}

// ── Public (no auth) ───────────────────────────────────────────
Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/register', [AuthController::class, 'register']);
});

Route::prefix('v1/auth')->middleware(['auth:sanctum'])->group(function () {
    Route::post('refresh', [AuthController::class, 'refresh']);
});

// ── Authenticated: Tenant-scoped ───────────────────────────────
Route::prefix('v1/tenant')->middleware(['tenant.api'])->group(function () {
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::get('debug-auth', function (Request $request) {
        return response()->json([
            'headers' => $request->headers->all(),
            'bearer' => $request->bearerToken(),
            'tenant' => app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'none',
            'db' => DB::connection()->getDatabaseName(),
        ]);
    });

    Route::middleware(['auth:sanctum', 'license.active'])->group(function () {
        require base_path('routes/tenant_api.php');
    });
});
