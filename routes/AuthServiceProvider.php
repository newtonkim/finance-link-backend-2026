<?php

use Illuminate\Support\Facades\Route;

/**
 * Add a macro to handle permission groups
 */
Route::macro('withPermissionGroups', function ($permissionGroup, $callback, $modules = []) {

    // Create a route group automatically with JWT, permissions, and translations middleware
    return Route::group([
        'middleware' => [
            'jwt.verify',                       // JWT auth
            'permission.access:'.$permissionGroup, // pass the module/group to your middleware
            'translations',                      // optional translations middleware
        ],
        'modules' => $modules, // optional metadata you can read inside middleware
    ], $callback);
});
