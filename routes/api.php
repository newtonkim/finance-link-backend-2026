<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Globals\GlobalHelpers;
use App\Tenant\Http\Controllers\Api\V1\MemberAuthController;
use App\Tenant\Http\Controllers\Api\V1\MemberPortalController;
use App\Tenant\Http\Controllers\Api\V1\MemberTransactionRequestController;
use App\Tenant\Http\Controllers\Api\V1\PublicBrandingController;
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

    Route::prefix('member')->group(function () {
        Route::post('auth/login', [MemberAuthController::class, 'login']);

        Route::middleware(['auth:sanctum', 'license.active', 'member.api'])->group(function () {
            Route::post('auth/logout', [MemberAuthController::class, 'logout']);
            Route::get('me', [MemberAuthController::class, 'me']);
            Route::get('accounts', [MemberPortalController::class, 'accounts']);
            Route::get('accounts/{id}', [MemberPortalController::class, 'account'])->whereNumber('id');
            Route::get('accounts/{id}/statement', [MemberPortalController::class, 'accountStatement'])->whereNumber('id');
            Route::get('statement', [MemberPortalController::class, 'statement']);
            Route::post('deposit-requests', [MemberTransactionRequestController::class, 'storeDeposit']);
            Route::post('withdrawal-requests', [MemberTransactionRequestController::class, 'storeWithdrawal']);
            Route::get('transaction-requests', [MemberTransactionRequestController::class, 'index']);
        });
    });

    // Public branding for the tenant login page (no auth required).
    Route::get('public-branding', [PublicBrandingController::class, 'show']);

    Route::get('debug-auth', function (Request $request) {
        return response()->json([
            'headers' => $request->headers->all(),
            'bearer' => $request->bearerToken(),
            'tenant' => app()->bound('currentTenant') ? app('currentTenant')->subdomain : 'none',
            'db' => DB::connection()->getDatabaseName(),
        ]);
    });

    Route::middleware(['auth:sanctum', 'license.active', 'staff.api'])->group(function () {
        require base_path('routes/tenant_api.php');
    });
});
