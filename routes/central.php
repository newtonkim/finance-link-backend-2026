<?php

use App\Central\Http\Controllers\DashboardController;
use App\Central\Http\Controllers\LicenseController;
use App\Central\Http\Controllers\SettingsController;
use App\Central\Http\Controllers\StaffController;
use App\Central\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'central.domain'])
    ->prefix('v1/central')
    ->name('central.')
    ->group(function () {
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::apiResource('/tenants', TenantController::class);
        Route::post('/tenants/list', [TenantController::class, 'get_tenants_list']);
        Route::get('/plans', [TenantController::class, 'plans']);
        Route::apiResource('/licenses', LicenseController::class);
        Route::post('/licenses/list', [LicenseController::class, 'get_licenses_list']);

        // Branding
        Route::get('/settings/branding', [SettingsController::class, 'get_branding']);
        Route::post('/settings/branding', [SettingsController::class, 'update_branding']);

        // Platform users (staff)
        Route::match(['get', 'post'], '/staff/list', [StaffController::class, 'get_staff_list']);
        Route::post('/staff/create', [StaffController::class, 'staff_create']);
        Route::get('/staff/{id}', [StaffController::class, 'get_staff_details']);
        Route::delete('/staff/{id}', [StaffController::class, 'delete_staff']);
    });
