<?php

use App\Central\Http\Controllers\DashboardController;
use App\Central\Http\Controllers\LicenseController;
use App\Central\Http\Controllers\ProfileController;
use App\Central\Http\Controllers\SettingsController;
use App\Central\Http\Controllers\StaffController;
use App\Central\Http\Controllers\TenantController;
use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'central.domain', 'central.auth'])
    ->prefix('v1/central')
    ->name('central.')
    ->group(function () {
        Route::get('/me', [AuthController::class, 'user'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        // Self-service profile for the signed-in platform user. Not permission-gated:
        // every authenticated central user may read and edit their own record.
        Route::prefix('profile')->name('profile.')->group(function () {
            Route::get('/', [ProfileController::class, 'show'])->name('show');
            Route::post('/update', [ProfileController::class, 'update'])->name('update');
            Route::post('/delete', [ProfileController::class, 'destroy'])->name('delete');
        });

        Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
            ->middleware('permission:dashboard-module-link-view')
            ->name('dashboard.summary');
        Route::post('/dashboard/analytics', [DashboardController::class, 'dashboard_analytics'])
            ->middleware('permission:dashboard-module-link-view')
            ->name('dashboard.analytics');

        Route::get('/plans', [TenantController::class, 'plans'])
            ->middleware('permission:settings-plans-list')
            ->name('plans.index');

        Route::post('/global/plans-drop-down', [SettingsController::class, 'plans_drop_down'])
            ->middleware('permission:settings-plans-list')
            ->name('global-plans-drop-down');

        Route::prefix('tenants')->name('tenants.')->group(function () {
            Route::get('/', [TenantController::class, 'index'])
                ->middleware('permission:tenants-list')
                ->name('index');
            Route::post('/', [TenantController::class, 'store'])
                ->middleware('permission:tenants-create')
                ->name('store');
            Route::get('/{tenant}', [TenantController::class, 'show'])
                ->middleware('permission:tenants-view-table-details')
                ->name('show');
            Route::match(['put', 'patch'], '/{tenant}', [TenantController::class, 'update'])
                ->middleware('permission:tenants-update')
                ->name('update');
            Route::delete('/{tenant}', [TenantController::class, 'destroy'])
                ->middleware('permission:tenants-delete')
                ->name('destroy');

            Route::post('/list', [TenantController::class, 'get_tenants_list'])
                ->middleware('permission:tenants-list')
                ->name('list');
            Route::post('/create', [TenantController::class, 'store'])
                ->middleware('permission:tenants-create')
                ->name('create');
            Route::post('/delete', [TenantController::class, 'delete_tenant'])
                ->middleware('permission:tenants-delete')
                ->name('delete');
            Route::post('/details', [TenantController::class, 'get_tenant_details'])
                ->middleware('permission:tenants-view-table-details')
                ->name('details');
            Route::post('/tenants-drop-down', [TenantController::class, 'get_tenants_drop_down'])
                ->middleware('permission:tenants-list')
                ->name('drop-down');
        });

        Route::prefix('licenses')->name('licenses.')->group(function () {
            Route::get('/', [LicenseController::class, 'index'])
                ->middleware('permission:licenses-list')
                ->name('index');
            Route::post('/', [LicenseController::class, 'store'])
                ->middleware('permission:licenses-create')
                ->name('store');
            Route::get('/{license}', [LicenseController::class, 'show'])
                ->middleware('permission:licenses-view-table-details')
                ->name('show');
            Route::match(['put', 'patch'], '/{license}', [LicenseController::class, 'update'])
                ->middleware('permission:licenses-update')
                ->name('update');
            Route::delete('/{license}', [LicenseController::class, 'destroy'])
                ->middleware('permission:licenses-delete')
                ->name('destroy');

            Route::post('/list', [LicenseController::class, 'get_licenses_list'])
                ->middleware('permission:licenses-list')
                ->name('list');
            Route::post('/stats', [LicenseController::class, 'get_license_stats'])
                ->middleware('permission:licenses-list')
                ->name('stats');
            Route::post('/create', [LicenseController::class, 'create_licenses_list'])
                ->middleware('permission:licenses-create')
                ->name('create');
            Route::post('/delete', [LicenseController::class, 'delete_licenses'])
                ->middleware('permission:licenses-delete')
                ->name('delete');
            Route::post('/details', [LicenseController::class, 'licenses_details'])
                ->middleware('permission:licenses-view-table-details')
                ->name('details');
            Route::post('/edit-details', [LicenseController::class, 'edit_licenses_details'])
                ->middleware('permission:licenses-update')
                ->name('edit-details');
            Route::post('/renewal-preview', [LicenseController::class, 'renewal_preview'])
                ->middleware('permission:licenses-update')
                ->name('renewal-preview');
            Route::post('/renew', [LicenseController::class, 'renew_license'])
                ->middleware('permission:licenses-update')
                ->name('renew');
            Route::post('/confirm-payment', [LicenseController::class, 'confirm_payment'])
                ->middleware('permission:licenses-update')
                ->name('confirm-payment');
            Route::post('/invoices', [LicenseController::class, 'invoices'])
                ->middleware('permission:licenses-list')
                ->name('invoices');
            Route::match(['get', 'post'], '/invoice/download', [LicenseController::class, 'download_invoice'])
                ->middleware('permission:licenses-list')
                ->name('invoice.download');
            Route::post('/invoice/email', [LicenseController::class, 'email_invoice'])
                ->middleware('permission:licenses-update')
                ->name('invoice.email');
            Route::post('/licenses-drop-down', [LicenseController::class, 'get_licenses_drop_down'])
                ->middleware('permission:licenses-list')
                ->name('drop-down');
        });

        Route::prefix('staff')->name('staff.')->group(function () {
            Route::match(['get', 'post'], '/list', [StaffController::class, 'get_staff_list'])
                ->middleware('permission:staff-list')
                ->name('list');
            Route::post('/create', [StaffController::class, 'staff_create'])
                ->middleware('permission:staff-create')
                ->name('create');
            Route::post('/details', [StaffController::class, 'get_staff_details'])
                ->middleware('permission:staff-details')
                ->name('details');
            Route::get('/{id}', [StaffController::class, 'get_staff_details'])
                ->middleware('permission:staff-details')
                ->name('show');
            Route::match(['delete', 'post'], '/delete', [StaffController::class, 'delete_staff'])
                ->middleware('permission:staff-delete')
                ->name('delete');
            Route::delete('/{id}', [StaffController::class, 'delete_staff'])
                ->middleware('permission:staff-delete')
                ->name('destroy');
            Route::post('/roles-drop-down', [StaffController::class, 'roles_drop_down'])
                ->middleware('permission:staff-roles-drop-down')
                ->name('roles-drop-down');
            Route::post('/users-drop-down', [StaffController::class, 'users_drop_down'])
                ->middleware('permission:staff-list')
                ->name('users-drop-down');
        });

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/branding', [SettingsController::class, 'get_branding'])
                ->middleware('permission:settings-general-view')
                ->name('branding.show');
            Route::post('/branding', [SettingsController::class, 'update_branding'])
                ->middleware('permission:settings-general-view')
                ->name('branding.update');

            Route::prefix('currency')->name('currency.')->group(function () {
                Route::post('/show', [SettingsController::class, 'get_currency_settings'])
                    ->middleware('permission:settings-general-view')
                    ->name('show');
                Route::post('/update', [SettingsController::class, 'update_currency_settings'])
                    ->middleware('permission:settings-general-view')
                    ->name('update');
            });

            Route::prefix('permisions')->name('permissions.')->group(function () {
                Route::post('/list', [SettingsController::class, 'get_permissions_list'])
                    ->middleware('permission:settings-permissions-list')
                    ->name('list');
                Route::post('/delete', [SettingsController::class, 'permissions_remove_ability'])
                    ->middleware('permission:settings-permissions-delete')
                    ->name('delete');
                Route::post('/details', [SettingsController::class, 'get_permissions_details'])
                    ->middleware('permission:settings-permissions-list')
                    ->name('details');
                Route::post('/holders_list', [SettingsController::class, 'permissions_holders_list'])
                    ->middleware('permission:settings-permissions-holders-list')
                    ->name('holders-list');
                Route::post('/remove_ability', [SettingsController::class, 'permissions_remove_ability'])
                    ->middleware('permission:settings-permissions-remove-ability')
                    ->name('remove-ability');
                Route::post('/add_ability', [SettingsController::class, 'permissions_add_ability'])
                    ->middleware('permission:settings-permissions-add-ability')
                    ->name('add-ability');
            });

            Route::prefix('roles')->name('roles.')->group(function () {
                Route::post('/list', [SettingsController::class, 'get_roles_list'])
                    ->middleware('permission:settings-roles-list')
                    ->name('list');
                Route::post('/create', [SettingsController::class, 'roles_create'])
                    ->middleware('permission:settings-roles-create')
                    ->name('create');
                Route::post('/delete', [SettingsController::class, 'roles_remove_ability'])
                    ->middleware('permission:settings-roles-delete')
                    ->name('delete');
                Route::post('/holders_list', [SettingsController::class, 'roles_holders_list'])
                    ->middleware('permission:settings-roles-holders-list')
                    ->name('holders-list');
                Route::post('/remove_ability', [SettingsController::class, 'roles_remove_ability'])
                    ->middleware('permission:settings-roles-remove-ability')
                    ->name('remove-ability');
                Route::post('/add_ability', [SettingsController::class, 'roles_add_ability'])
                    ->middleware('permission:settings-roles-add-ability')
                    ->name('add-ability');
                Route::post('/permissions-drop-down', [SettingsController::class, 'permissions_drop_down'])
                    ->middleware('permission:settings-permissions-list')
                    ->name('permissions-drop-down');
            });

            Route::prefix('plans')->name('plans.')->group(function () {
                Route::post('/list', [SettingsController::class, 'get_plans_list'])
                    ->middleware('permission:settings-plans-list')
                    ->name('list');
                Route::post('/create', [SettingsController::class, 'plans_create'])
                    ->middleware('permission:settings-plans-create')
                    ->name('create');
                Route::post('/delete', [SettingsController::class, 'plans_delete'])
                    ->middleware('permission:settings-plans-delete')
                    ->name('delete');
                Route::post('/details', [SettingsController::class, 'get_plans_details'])
                    ->middleware('permission:settings-plans-details')
                    ->name('details');
                Route::post('/stats', [SettingsController::class, 'get_plans_stats'])
                    ->middleware('permission:settings-plans-list')
                    ->name('stats');
            });

            Route::prefix('features')->name('features.')->group(function () {
                Route::post('/list', [SettingsController::class, 'get_features_list'])
                    ->middleware('permission:settings-plans-list')
                    ->name('list');
                Route::post('/create', [SettingsController::class, 'features_create'])
                    ->middleware('permission:settings-plans-create')
                    ->name('create');
                Route::post('/update', [SettingsController::class, 'features_update'])
                    ->middleware('permission:settings-plans-create')
                    ->name('update');
                Route::post('/delete', [SettingsController::class, 'features_delete'])
                    ->middleware('permission:settings-plans-delete')
                    ->name('delete');
            });
        });
    });
