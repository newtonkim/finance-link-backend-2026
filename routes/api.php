<?php

use App\Central\Http\Controllers\DashboardController;
use App\Central\Http\Controllers\LicenseController;
use App\Central\Http\Controllers\SettingsController;
use App\Central\Http\Controllers\StaffController;
use App\Central\Http\Controllers\TenantController;
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

// ── Authenticated: Central (super-admin / platform) ────────────
Route::prefix('v1/central')->middleware(['verified'])->group(function () {
    Route::get('me', [AuthController::class, 'user']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::apiResource('tenants', TenantController::class);
});

Route::group(['prefix' => 'v1/central/',  'middleware' => []], function () {
    Route::group(['prefix' => 'global/', 'controller' => SettingsController::class, 'middleware' => []], function () {
        Route::post('plans-drop-down', 'plans_drop_down')->name('global-plans-drop-down');
    });

    Route::group(['prefix' => 'dashboard/', 'controller' => DashboardController::class, 'middleware' => []], function () {
        Route::post('analytics', 'dashboard_analytics');
    });
    Route::group(['prefix' => 'tenants/', 'controller' => TenantController::class, 'middleware' => []], function () {
        Route::post('list', 'get_tenants_list');
        Route::post('create', 'store');
        Route::post('delete', 'delete_tenant');
        Route::post('details', 'get_tenant_details');
        Route::post('tenants-drop-down', 'get_tenants_drop_down');
    });

    Route::group(['prefix' => 'licenses/', 'controller' => LicenseController::class, 'middleware' => []], function () {
        Route::post('list', 'get_licenses_list');
        Route::post('stats', 'get_license_stats');
        Route::post('create', 'create_licenses_list');
        Route::post('delete', 'delete_licenses');
        Route::post('details', 'licenses_details');
        Route::post('edit-details', 'edit_licenses_details');
        Route::post('renewal-preview', 'renewal_preview');
        Route::post('renew', 'renew_license');
        Route::post('invoices', 'invoices');
        Route::post('licenses-drop-down', 'get_licenses_drop_down');
    });

    Route::group(['prefix' => 'staff/', 'controller' => StaffController::class, 'middleware' => []], function () {
        // routeList(['list', 'create', 'delete', 'details'], 'staff', 'staff-');
        // routeList([   'details'], 'staff', 'platform-');
           routeListV2([
            [
                'route' => 'details',
                'method' => 'get_staff_details',
            ],
            [
                'route' => 'roles-drop-down',
                'method' => 'roles_drop_down',
            ],
            [
                'route' => 'delete',
                'method' => 'delete_staff',
            ],
            [
                'route' => 'create',
                'method' => 'staff_create',
            ],
            [
                'route' => 'list',
                'method' => 'get_staff_list',
            ],
            [
                'route' => 'roles-drop-down',
                'method' => 'roles_drop_down',
            ],
            ]);
        Route::post('users-drop-down', 'users_drop_down')->name('platform-staff-users-drop-down');
    });
    Route::group(['prefix' => 'settings/', 'controller' => SettingsController::class, 'middleware' => []], function () {

        Route::group(['prefix' => 'permisions/'], function () {
            routeList(['list', 'delete', 'details', 'holders_list', 'remove_ability', 'add_ability'], 'permissions', 'platform-settings-');
        });
        Route::group(['prefix' => 'roles/'], function () {
            routeList(['list', 'create', 'delete', 'holders_list', 'remove_ability', 'add_ability'], 'roles', 'platform-settings-');
            Route::post('permissions-drop-down', 'permissions_drop_down');
        });

        Route::group(['prefix' => 'plans/'], function () {
            routeList(['list', 'create', 'delete', 'details'], 'plans', 'settings-');
            Route::post('stats', [SettingsController::class, 'get_plans_stats'])->name('settings-plans-stats');
        });

        Route::group(['prefix' => 'features/'], function () {
            routeList(['list', 'create', 'delete'], 'features', 'settings-');
        });
    });
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

    Route::middleware('auth:sanctum')->group(function () {
        require base_path('routes/tenant_api.php');
    });
});
